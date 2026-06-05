<?php

declare(strict_types=1);

namespace Drupal\eic_wysiwyg\Plugin\Field\FieldFormatter;

use Drupal\Core\Field\Attribute\FieldFormatter;
use Drupal\Core\Field\FieldDefinitionInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\Core\Path\PathValidatorInterface;
use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\eic_wysiwyg\EclLinkDecorator;
use Drupal\link\Plugin\Field\FieldFormatter\LinkFormatter;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Link formatter that applies ECL external-link markup.
 */
#[FieldFormatter(
  id: 'ecl_link',
  label: new TranslatableMarkup('ECL Link'),
  field_types: ['link'],
)]
class EclLinkFormatter extends LinkFormatter {

  protected EclLinkDecorator $eclLinkDecorator;

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    $instance = new static(
      $plugin_id,
      $plugin_definition,
      $configuration['field_definition'],
      $configuration['settings'],
      $configuration['label'],
      $configuration['view_mode'],
      $configuration['third_party_settings'],
      $container->get('path.validator'),
    );
    $instance->eclLinkDecorator = $container->get('eic_wysiwyg.ecl_link_decorator');
    return $instance;
  }

  public function __construct($plugin_id, $plugin_definition, FieldDefinitionInterface $field_definition, array $settings, $label, $view_mode, array $third_party_settings, PathValidatorInterface $path_validator) {
    parent::__construct($plugin_id, $plugin_definition, $field_definition, $settings, $label, $view_mode, $third_party_settings, $path_validator);
  }

  /**
   * {@inheritdoc}
   */
  public function viewElements(FieldItemListInterface $items, $langcode) {
    $elements = parent::viewElements($items, $langcode);
    foreach ($elements as $delta => $element) {
      if (isset($element['#type']) && $element['#type'] === 'link') {
        $elements[$delta] = $this->eclLinkDecorator->decorate($element);
      }
    }
    return $elements;
  }

}
