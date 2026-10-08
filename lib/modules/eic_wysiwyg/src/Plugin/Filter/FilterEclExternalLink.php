<?php

declare(strict_types=1);

namespace Drupal\eic_wysiwyg\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;
use Drupal\oe_theme_helper\ExternalLinks;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a filter to transform external links into ECL links.
 *
 * @Filter(
 *   id = "filter_ecl_external_link",
 *   title = @Translation("ECL external link support"),
 *   description = @Translation("Adds ECL classes, attributes and the external icon to external links."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE
 * )
 */
class FilterEclExternalLink extends FilterBase implements ContainerFactoryPluginInterface {

  /**
   * The external links helper service.
   *
   * @var \Drupal\oe_theme_helper\ExternalLinks
   */
  protected ExternalLinks $externalLinks;

  /**
   * Constructs a FilterEclExternalLink object.
   *
   * @param array $configuration
   *   The plugin configuration.
   * @param string $plugin_id
   *   The plugin ID.
   * @param mixed $plugin_definition
   *   The plugin definition.
   * @param \Drupal\oe_theme_helper\ExternalLinks $external_links
   *   The external links helper service.
   */
  public function __construct(array $configuration, string $plugin_id, $plugin_definition, ExternalLinks $external_links) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->externalLinks = $external_links;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('oe_theme_helper.external_links')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    if (stripos($text, '<a ') === FALSE) {
      return $result;
    }

    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);

    /** @var \DOMElement $link */
    foreach ($xpath->query('//a[@href]') as $link) {
      $href = $link->getAttribute('href');

      // Only http(s) links are candidates; skip mailto:, tel:, fragments, etc.
      if (!preg_match('#^https?://#i', $href)) {
        continue;
      }

      if (!$this->externalLinks->isExternalLink($href)) {
        continue;
      }

      $this->addClasses($link, [
        'ecl-link',
        'ecl-link--standalone',
        'ecl-link--icon',
      ]);
      $link->setAttribute('target', '_blank');
      $this->addRelValues($link, ['noopener', 'noreferrer']);

      if (!$this->hasDescendantWithClass($xpath, $link, 'ecl-link__label')) {
        $label = $dom->createElement('span');
        $label->setAttribute('class', 'ecl-link__label');

        while ($link->firstChild) {
          $label->appendChild($link->firstChild);
        }
        $link->appendChild($label);
      }

      if (!$this->hasDescendantWithClass($xpath, $link, 'ecl-link__icon')) {
        $link->appendChild($this->createExternalIcon($dom));
      }
    }

    $result->setProcessedText(Html::serialize($dom));

    return $result;
  }

  /**
   * Adds classes to a DOM element while preserving existing classes.
   *
   * @param \DOMElement $element
   *   The DOM element.
   * @param array $classes
   *   The classes to add.
   */
  protected function addClasses(\DOMElement $element, array $classes): void {
    $existing_classes = array_filter(array_map('trim', explode(' ', $element->getAttribute('class'))));
    $element->setAttribute('class', implode(' ', array_unique(array_merge($existing_classes, $classes))));
  }

  /**
   * Adds rel values to a DOM element while preserving existing values.
   *
   * @param \DOMElement $element
   *   The DOM element.
   * @param array $values
   *   The rel values to add.
   */
  protected function addRelValues(\DOMElement $element, array $values): void {
    $existing_values = array_filter(array_map('trim', explode(' ', $element->getAttribute('rel'))));
    $element->setAttribute('rel', implode(' ', array_unique(array_merge($existing_values, $values))));
  }

  /**
   * Checks whether an element has a descendant with the given class.
   *
   * @param \DOMXPath $xpath
   *   The DOM XPath.
   * @param \DOMElement $element
   *   The DOM element.
   * @param string $class
   *   The class to look for.
   *
   * @return bool
   *   TRUE if a matching descendant exists.
   */
  protected function hasDescendantWithClass(\DOMXPath $xpath, \DOMElement $element, string $class): bool {
    $query = sprintf('.//*[contains(concat(" ", normalize-space(@class), " "), " %s ")]', $class);
    return $xpath->query($query, $element)->count() > 0;
  }

  /**
   * Creates an ECL external-link icon element.
   *
   * ECL 5 icons are painted by Webtools (load.js) from the wt-icon--* class,
   * matching oe_theme's ecl-icon component; there is no SVG sprite.
   *
   * @param \DOMDocument $dom
   *   The DOM document.
   *
   * @return \DOMElement
   *   The icon placeholder element.
   */
  protected function createExternalIcon(\DOMDocument $dom): \DOMElement {
    $icon = $dom->createElement('span');
    $icon->setAttribute('class', 'wt-icon--external ecl-icon ecl-icon--external ecl-icon--2xs ecl-link__icon');
    $icon->setAttribute('aria-hidden', 'true');

    return $icon;
  }

}
