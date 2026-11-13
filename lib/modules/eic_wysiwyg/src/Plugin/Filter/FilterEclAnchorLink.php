<?php

declare(strict_types=1);

namespace Drupal\eic_wysiwyg\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Provides a filter to decorate links that point at an anchor on the page.
 *
 * @Filter(
 *   id = "filter_ecl_anchor_link",
 *   title = @Translation("ECL anchor link support"),
 *   description = @Translation("Adds ECL classes and the chain icon to links that point at an anchor on the same page."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE
 * )
 */
class FilterEclAnchorLink extends FilterBase {

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
    $decorated = FALSE;

    /** @var \DOMElement $link */
    foreach ($xpath->query('//a[@href]') as $link) {
      if (!$this->isAnchorLink($link->getAttribute('href'))) {
        continue;
      }

      // Decoration is for text links. A link with no text of its own, such as
      // one that only wraps an image, would end up with a stray icon.
      if (trim($link->textContent) === '') {
        continue;
      }

      $this->addClasses($link, [
        'ecl-link',
        'ecl-link--standalone',
        'ecl-link--icon',
      ]);

      if (!$this->hasDescendantWithClass($xpath, $link, 'ecl-link__label')) {
        $label = $dom->createElement('span');
        $label->setAttribute('class', 'ecl-link__label');

        while ($link->firstChild) {
          $label->appendChild($link->firstChild);
        }
        $link->appendChild($label);
      }

      if (!$this->hasDescendantWithClass($xpath, $link, 'ecl-link__icon')) {
        $link->appendChild($this->createAnchorIcon($dom));
      }

      $decorated = TRUE;
    }

    // Leave the text exactly as it came in when there was nothing to decorate,
    // rather than passing it through a parse and serialise for no change.
    if (!$decorated) {
      return $result;
    }

    $result->setProcessedText(Html::serialize($dom));

    return $result;
  }

  /**
   * Checks whether an href points at an anchor on the current page.
   *
   * That is a fragment with at least one character after the "#". A bare "#"
   * (or "#" and whitespace) is not an anchor link: it is usually a hook for
   * JavaScript. Absolute URLs that happen to carry a fragment are not matched.
   *
   * @param string $href
   *   The href attribute value.
   *
   * @return bool
   *   TRUE if the href is a same-page fragment link.
   */
  protected function isAnchorLink(string $href): bool {
    $href = trim($href);
    return strlen($href) > 1 && $href[0] === '#';
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
   * Creates the chain icon element.
   *
   * ECL has no anchor icon, so a link to an anchor uses ECL's "chain" icon.
   * ECL 5 icons are painted by Webtools (load.js) from the wt-icon--* class,
   * the same way as the external-link icon; there is no SVG sprite.
   *
   * @param \DOMDocument $dom
   *   The DOM document.
   *
   * @return \DOMElement
   *   The icon placeholder element.
   */
  protected function createAnchorIcon(\DOMDocument $dom): \DOMElement {
    $icon = $dom->createElement('span');
    $icon->setAttribute('class', 'wt-icon--chain ecl-icon ecl-icon--chain ecl-icon--2xs ecl-link__icon');
    $icon->setAttribute('aria-hidden', 'true');

    return $icon;
  }

}
