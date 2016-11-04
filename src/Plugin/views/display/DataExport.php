<?php

namespace Drupal\views_data_export\Plugin\views\display;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Form\FormStateInterface;
use Drupal\rest\Plugin\views\display\RestExport;
use Drupal\views\Plugin\views\display\DefaultDisplay;
use Drupal\views\ViewExecutable;
use Drupal\views\Views;

/**
 * Provides a data export display plugin.
 *
 * This overrides the REST Export display to make labeling clearer on the admin
 * UI, and to allow attaching of these to other displays.
 *
 * @ingroup views_display_plugins
 *
 * @ViewsDisplay(
 *   id = "data_export",
 *   title = @Translation("Data export"),
 *   help = @Translation("Export the view results to a file. Can handle very large result sets."),
 *   uses_route = TRUE,
 *   admin = @Translation("Data export"),
 *   returns_response = TRUE
 * )
 */
class DataExport extends RestExport {

  /**
   * {@inheritdoc}
   */
  public static function buildResponse($view_id, $display_id, array $args = []) {
    // Do not call the parent method, as it makes the response harder to alter.
    // @see https://www.drupal.org/node/2779807

    // Get the executable view.
    $view = Views::getView($view_id);
    $formats = $view->storage->getDisplay($display_id)['display_options']['style']['options']['formats'];
    // Use batch for CSV mode only.
    // @todo: Move the batch call?
    if (in_array('csv', $formats)) {
      // Get the current display.
      $display = $view->getDisplay($display_id);
      // Number of items to process per batch.
      // @todo: Get default number of items.
      $items_per_batch = 50;
      // Create a batch definition.
      $batch_definition = [
        'operations' => [
          [__CLASS__, 'process'], [$view, $display, $items_per_batch],
        ],
        'finished' => [__CLASS__, 'finish'],
      ];
      batch_set($batch_definition);
    }

    $build = static::buildBasicRenderable($view_id, $display_id, $args);

    // Setup an empty response, so for example, the Content-Disposition header
    // can be set.
    $response = new CacheableResponse('', 200);
    $build['#response'] = $response;

    /** @var \Drupal\Core\Render\RendererInterface $renderer */
    $renderer = \Drupal::service('renderer');

    $output = (string) $renderer->renderRoot($build);

    $response->setContent($output);
    $cache_metadata = CacheableMetadata::createFromRenderArray($build);
    $response->addCacheableDependency($cache_metadata);

    $response->headers->set('Content-type', $build['#content_type']);

    return $response;
  }

  /**
   * {@inheritdoc}
   */
  public function render() {

    // Add the content disposition header if a custom filename has been used.
    if (($response = $this->view->getResponse()) && $this->getOption('filename')) {
      $response->headers->set('Content-Disposition', 'attachment; filename="' . $this->generateFilename($this->getOption('filename')) . '"');
    }

    return parent::render();
  }

  /**
   * Given a filename and a view, generate a filename.
   *
   * @param $filename_pattern
   *   The filename, which may contain replacement tokens.
   * @return string
   *   The filename with any tokens replaced.
   */
  protected function generateFilename($filename_pattern) {
    return $this->globalTokenReplace($filename_pattern);
  }

  /**
   * {@inheritdoc}
   */
  protected function defineOptions() {
    $options = parent::defineOptions();

    $options['displays'] = ['default' => []];

    // Set the default style plugin, and default to fields.
    $options['style']['contains']['type']['default'] = 'data_export';
    $options['row']['contains']['type']['default'] = 'data_field';

    return $options;
  }

  /**
   * {@inheritdoc}
   */
  public function optionsSummary(&$categories, &$options) {
    parent::optionsSummary($categories, $options);

    $displays = array_filter($this->getOption('displays'));
    if (count($displays) > 1) {
      $attach_to = $this->t('Multiple displays');
    }
    elseif (count($displays) == 1) {
      $display = array_shift($displays);
      $displays = $this->view->storage->get('display');
      if (!empty($displays[$display])) {
        $attach_to = $displays[$display]['display_title'];
      }
    }

    if (!isset($attach_to)) {
      $attach_to = $this->t('None');
    }

    $options['displays'] = array(
      'category' => 'path',
      'title' => $this->t('Attach to'),
      'value' => $attach_to,
    );

    // Add filename to the summary if set.
    if ($this->getOption('filename')) {
      $options['path']['value'] .= $this->t(' (@filename)', ['@filename' => $this->getOption('filename')]);
    }

    // Display the selected format from the style plugin if available.
    $style_options = $this->getOption('style')['options'];
    if (!empty($style_options['formats'])) {
      $options['style']['value'] .= $this->t(' (@export_format)', ['@export_format' => reset($style_options['formats'])]);
    }
  }
    /**
   * {@inheritdoc}
   */
  public function buildOptionsForm(&$form, FormStateInterface $form_state) {
    parent::buildOptionsForm($form, $form_state);

    // Remove the 'serializer' option to avoid confusion.
    switch ($form_state->get('section')) {
      case 'style':
        unset($form['style']['type']['#options']['serializer']);
        break;

      case 'path':
        $form['filename'] = [
          '#type' => 'textfield',
          '#title' => $this->t('Filename'),
          '#default_value' => $this->options['filename'],
          '#description' => $this->t('The filename that will be suggested to the browser for downloading purposes. You may include replacement patterns from the list below.'),
        ];
        // Support tokens.
        $this->globalTokenForm($form, $form_state);
        break;

      case 'displays':
        $form['#title'] .= $this->t('Attach to');
        $displays = [];
        foreach ($this->view->storage->get('display') as $display_id => $display) {
          if ($this->view->displayHandlers->has($display_id) && $this->view->displayHandlers->get($display_id)->acceptAttachments()) {
            $displays[$display_id] = $display['display_title'];
          }
        }
        $form['displays'] = [
          '#title' => $this->t('Displays'),
          '#type' => 'checkboxes',
          '#description' => $this->t('The data export icon will be available only to the selected displays.'),
          '#options' => array_map('\Drupal\Component\Utility\Html::escape', $displays),
          '#default_value' => $this->getOption('displays'),
        ];
        break;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function attachTo(ViewExecutable $clone, $display_id, array &$build) {
    $displays = $this->getOption('displays');
    if (empty($displays[$display_id])) {
      return;
    }

    // Defer to the feed style; it may put in meta information, and/or
    // attach a feed icon.
    $clone->setArguments($this->view->args);
    $clone->setDisplay($this->display['id']);
    $clone->buildTitle();
    if ($plugin = $clone->display_handler->getPlugin('style')) {
      $plugin->attachTo($build, $display_id, $clone->getUrl(), $clone->getTitle());
      foreach ($clone->feedIcons as $feed_icon) {
        $this->view->feedIcons[] = $feed_icon;
      }
    }

    // Clean up.
    $clone->destroy();
    unset($clone);
  }

  /**
   * {@inheritdoc}
   */
  public function submitOptionsForm(&$form, FormStateInterface $form_state) {
    parent::submitOptionsForm($form, $form_state);
    $section = $form_state->get('section');
    switch ($section) {
      case 'displays':
        $this->setOption($section, $form_state->getValue($section));
        break;

      case 'path':
        $this->setOption('filename', $form_state->getValue('filename'));
        break;
    }
  }

  /**
   * Processes the export operation.
   *
   * @param \Drupal\views\ViewExecutable $view
   *   The executable view.
   * @param \Drupal\views\Plugin\views\display\DefaultDisplay $display
   *   Data export display.
   * @param int $items
   *   The number of items to load per batch process.
   * @param mixed &$context
   *   The current batch context.
   */
  public static function process(ViewExecutable $view, DefaultDisplay $display, $items, &$context) {
    if (empty($context['sandbox'])) {
      $context['sandbox']['progress'] = 0;
      $context['sandbox']['items'] = $items;
      $context['sandbox']['offset'] = (int) $view->getOffset();
      // @todo: Use the views query.
      $context['sandbox']['max'] = (int) db_query('SELECT COUNT(uid) FROM users')->fetchField() - 1;

      // Create a CSV file.
      $filename = $view->id() . '_' . $display->getBaseId() . '_export.csv';
      $file_path = file_directory_temp() . '/' . $filename;
      $csv_file = fopen($file_path, 'w');
      $field_labels = ['uid'];
      // @todo: Use getFieldLabels().
      // $field_labels = array_keys($display->getFieldLabels());
      // Add headers/file names into the file.
      fputcsv($csv_file, $field_labels);
      fclose($csv_file);

      $context['sandbox']['file'] = $file_path;
      $context['sandbox']['fields'] = $field_labels;
    }

    // Load items in chunks.
    $view->setItemsPerPage($context['sandbox']['items']);
    $view->setOffset($context['sandbox']['offset']);
    $view->execute();

    // Update the new offset value.
    $context['sandbox']['offset'] += $context['sandbox']['items'];

    // Open the CSV file.
    $csv_file = fopen($context['sandbox']['file'], 'a');

    foreach ($view->result as $row) {
      // @todo: Add the row values.
      fputcsv($csv_file, [$row->uid]);
      $context['results'][] = $row->uid . ' : user';
      $context['sandbox']['progress']++;
    }
    if ($context['sandbox']['progress'] != $context['sandbox']['max']) {
      $context['finished'] = $context['sandbox']['progress'] / $context['sandbox']['max'];
    }

    // Close the file.
    fclose($csv_file);
  }

  /**
   * Finishes the export batch.
   */
  public static function finish($success, $results, $operations) {
    // Check if the batch job was successful.
    if ($success) {
      drupal_set_message(t('%count items exported.', ['%count' => count($results)]));
    }
  }

}
