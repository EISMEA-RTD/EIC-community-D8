<?php

declare(strict_types=1);

namespace Drupal\eic_wysiwyg;

use Drupal\Core\Url;
use Drupal\oe_theme_helper\ExternalLinks;

/**
 * Decorates link render arrays with ECL external-link markup.
 */
class EclLinkDecorator {

  public function __construct(
    protected ExternalLinks $externalLinks,
  ) {}

  /**
   * Decorates a #type=>link render array if the URL is external.
   *
   * @param array $element
   *   A render array with at least '#type' => 'link', '#title', '#url'.
   *
   * @return array
   *   The (possibly decorated) render array.
   */
  public function decorate(array $element): array {
    if (!isset($element['#url']) || !$element['#url'] instanceof Url) {
      return $element;
    }
    if (!$this->isDecoratable($element['#url'])) {
      return $element;
    }

    $title = $element['#title'] ?? '';

    // ECL 5 icons are painted by Webtools (load.js) from the wt-icon--* class,
    // matching oe_theme's ecl-icon component; there is no SVG sprite.
    $element['#title'] = [
      '#type' => 'inline_template',
      '#template' => '<span class="ecl-link__label">{{ label }}</span><span class="wt-icon--external ecl-icon ecl-icon--external ecl-icon--2xs ecl-link__icon" aria-hidden="true"></span>',
      '#context' => [
        'label' => $title,
      ],
    ];

    $options = $element['#options'] ?? [];
    $attributes = $options['attributes'] ?? [];

    $classes = isset($attributes['class']) ? (array) $attributes['class'] : [];
    $attributes['class'] = array_values(array_unique(array_merge($classes, [
      'ecl-link',
      'ecl-link--standalone',
      'ecl-link--icon',
    ])));

    $attributes['target'] = '_blank';
    $rel = isset($attributes['rel']) ? preg_split('/\s+/', (string) $attributes['rel'], -1, PREG_SPLIT_NO_EMPTY) : [];
    $attributes['rel'] = implode(' ', array_values(array_unique(array_merge($rel, ['noopener', 'noreferrer']))));

    $options['attributes'] = $attributes;
    $element['#options'] = $options;

    return $element;
  }

  /**
   * Checks whether a URL is an external http(s) URL.
   */
  protected function isDecoratable(Url $url): bool {
    if (!$url->isExternal()) {
      return FALSE;
    }
    $uri = $url->getUri();
    if (!preg_match('#^https?://#i', $uri)) {
      return FALSE;
    }
    return $this->externalLinks->isExternalLink($uri);
  }

}
