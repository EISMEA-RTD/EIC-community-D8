<?php

declare(strict_types=1);

namespace Drupal\eic_wysiwyg;

use Drupal\Core\Extension\ExtensionList;
use Drupal\Core\Extension\UnknownExtensionException;
use Drupal\Core\Url;
use Drupal\oe_theme_helper\ExternalLinks;

/**
 * Decorates link render arrays with ECL external-link markup.
 */
class EclLinkDecorator {

  private const FALLBACK_ICON_PATH = '/themes/contrib/oe_theme/dist/ecl/images/icons/sprites/icons.svg';

  public function __construct(
    protected ExternalLinks $externalLinks,
    protected ExtensionList $themeExtensionList,
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
    $icon_path = $this->getIconPath();

    $element['#title'] = [
      '#type' => 'inline_template',
      '#template' => '<span class="ecl-link__label">{{ label }}</span><svg class="ecl-icon ecl-icon--2xs ecl-link__icon" focusable="false" aria-hidden="true"><use href="{{ icon_path }}#external" xlink:href="{{ icon_path }}#external"></use></svg>',
      '#context' => [
        'label' => $title,
        'icon_path' => $icon_path,
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

  /**
   * Returns the path to the ECL icon sprite.
   */
  protected function getIconPath(): string {
    try {
      return base_path() . $this->themeExtensionList->getPath('ddc_theme') . '/dist/eu/images/icons/sprites/icons.svg';
    }
    catch (UnknownExtensionException) {
      return self::FALLBACK_ICON_PATH;
    }
  }

}
