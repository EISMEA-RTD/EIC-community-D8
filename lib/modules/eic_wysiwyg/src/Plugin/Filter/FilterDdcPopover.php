<?php

declare(strict_types=1);

namespace Drupal\eic_wysiwyg\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Renders inline annotations as ECL Popover components.
 *
 * Editors mark a phrase in the WYSIWYG and attach secondary information to it.
 * That is stored as a single inline element carrying one attribute:
 *
 * @code
 * <span data-ddc-popover-content="Some detail. https://example.com">phrase</span>
 * @endcode
 *
 * A single inline element is a hard requirement, not a style choice: the
 * annotation lives inside body copy, and filter_autop and filter_htmlcorrector
 * would split the surrounding paragraph around any block-level markup.
 *
 * The stored content is plain text. Authors never write markup; they paste a
 * URL and this filter turns it into a link. Two pieces of structure survive:
 * - Line breaks, emitted as <br>.
 * - http(s) URLs, emitted as <a href>.
 * Both are built as DOM nodes from the plain text, never parsed from it, so
 * the popover can contain nothing else and is XSS-safe by construction.
 *
 * Links rule out ECL Tooltip: it assigns its popup with textContent, and a
 * hover popup closes before the pointer can reach a link inside it. ECL
 * Popover opens on click, stays open, and works on touch devices. Its CSS and
 * JS ship globally via oe_theme/component_library_ec, whose ecl_auto_init.js
 * picks up [data-ecl-auto-init]; so no JavaScript ships with this feature.
 *
 * The markup is ECL's popover structure built from <span>s rather than ECL's
 * <div>s, so it stays legal inside a <p>; css/popover.css restores the block
 * boxes ECL's CSS expects inside it.
 *
 * WEIGHT: this filter must run FIRST after filter_html, before every other
 * filter -- in particular before filter_autop and filter_url.
 *
 * Those filters are regex-based and do not respect attribute boundaries.
 * _filter_autop() splits text only on pre|script|style|object|iframe tags, so
 * it rewrites a newline inside the stored attribute into "<br />". filter_url
 * then treats the text between those injected tags as body copy and wraps any
 * URL in <a href="...">, whose quote ends the attribute early: the rest of
 * the content spills into the page as text.
 *
 * Running first means the attribute is consumed before any of that can
 * happen. Everything emitted here is phrasing content (span, button, br, a)
 * with no newlines, so autop leaves it alone and filter_url skips text that
 * is already inside a link. filter_html has already run, so it never sees
 * the emitted <button>s.
 *
 * @Filter(
 *   id = "filter_ddc_popover",
 *   title = @Translation("ECL inline popover"),
 *   description = @Translation("Renders inline annotations created in the editor as ECL Popover components, linking any URLs in them. Must run immediately after &quot;Limit allowed HTML tags&quot;, before every other filter."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   weight = -49,
 *   settings = {
 *     "show_close_button" = TRUE,
 *     "enable_hover" = FALSE,
 *     "inverted" = FALSE
 *   }
 * )
 */
class FilterDdcPopover extends FilterBase {

  /**
   * The attribute carrying the popover content on the stored markup.
   */
  public const CONTENT_ATTRIBUTE = 'data-ddc-popover-content';

  /**
   * Matches an http(s) URL in plain text.
   *
   * Stops at whitespace and at characters that cannot appear unencoded in a
   * URL. Trailing sentence punctuation is trimmed separately by linkify().
   */
  private const URL_PATTERN = '~https?://[^\s<>"\']+~iu';

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['show_close_button'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Show a close button inside the popover'),
      '#default_value' => $this->settings['show_close_button'] ?? TRUE,
      '#description' => $this->t('The popover can always be closed with Escape, by clicking outside it, or by clicking the trigger again.'),
    ];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function process($text, $langcode) {
    $result = new FilterProcessResult($text);

    if (stripos($text, self::CONTENT_ATTRIBUTE) === FALSE) {
      return $result;
    }

    $dom = Html::load($text);
    $xpath = new \DOMXPath($dom);
    $show_close = (bool) ($this->settings['show_close_button'] ?? TRUE);

    // Snapshot the node list: the tree is mutated while iterating.
    $triggers = iterator_to_array($xpath->query('//span[@' . self::CONTENT_ATTRIBUTE . ']'));

    $index = 0;
    $rendered = FALSE;

    /** @var \DOMElement $trigger */
    foreach ($triggers as $trigger) {
      $raw = $trigger->getAttribute(self::CONTENT_ATTRIBUTE);
      $trigger->removeAttribute(self::CONTENT_ATTRIBUTE);

      // A trigger nested in a link, a button, or (once converted) inside
      // another trigger's toggle would produce invalid, unclickable markup.
      // Document order guarantees an enclosing trigger is converted first, so
      // by the time we reach the inner one it already sits inside a <button>.
      if ($xpath->query('ancestor::a|ancestor::button', $trigger)->count() > 0) {
        $this->unwrap($trigger);
        continue;
      }

      $content = $this->toPlainText($raw);

      // An empty popover is worse than none: it renders a trigger that opens
      // a blank box.
      if ($content === '') {
        $this->unwrap($trigger);
        continue;
      }

      $this->renderPopover($dom, $trigger, $content, $index, $show_close);
      $rendered = TRUE;
      $index++;
    }

    $result->setProcessedText(Html::serialize($dom));

    if ($rendered) {
      $result->setAttachments(['library' => ['eic_wysiwyg/popover']]);
    }

    return $result;
  }

  /**
   * Reduces stored content to plain text, keeping single line breaks.
   *
   * Authors write plain text; any markup that reaches here is discarded rather
   * than rendered. Xss::filter() runs first so a malformed or nested tag
   * cannot survive strip_tags(). Entities are decoded so that a URL's "&"
   * is not double-encoded when it is written back out as a text node or href.
   *
   * Line breaks are kept, so an editor can separate a definition from a note.
   * Everything else collapses: runs of spaces and tabs become one space, and
   * blank lines collapse to a single break.
   *
   * @param string $raw
   *   The stored content.
   *
   * @return string
   *   Plain text with single line breaks, possibly empty.
   */
  protected function toPlainText(string $raw): string {
    $text = Html::decodeEntities(strip_tags(Xss::filter($raw, [])));

    // Normalise every line-break form to "\n" first: CKEditor stores a bare
    // CR, and content saved by earlier versions of this filter may carry
    // U+2028 or U+2029.
    $text = str_replace(["\r\n", "\r", "\u{2028}", "\u{2029}"], "\n", $text);
    // Collapse horizontal whitespace only -- \S excludes \n via the class.
    $text = preg_replace('/[^\S\n]+/u', ' ', $text);
    // Trim spaces hugging a break, then collapse blank lines.
    $text = preg_replace('/ *\n */u', "\n", $text);
    $text = preg_replace('/\n{2,}/u', "\n", $text);

    return trim($text);
  }

  /**
   * Replaces a trigger element with the full ECL Popover structure.
   *
   * ECL's popover.js makes no assumptions about tag names; it requires only
   * that the container's id matches the toggle's aria-controls, and that
   * .ecl-popover__scrollable is the container's first element child.
   *
   * @param \DOMDocument $dom
   *   The document being rewritten.
   * @param \DOMElement $trigger
   *   The stored trigger element, replaced in place.
   * @param string $content
   *   Plain-text popover content with single line breaks.
   * @param int $index
   *   The zero-based index of this popover within the text.
   * @param bool $show_close
   *   Whether to render the close button.
   */
  protected function renderPopover(\DOMDocument $dom, \DOMElement $trigger, string $content, int $index, bool $show_close): void {
    $id = $this->buildId($trigger->textContent, $content, $index);

    $root = $dom->createElement('span');
    $root->setAttribute('class', 'ecl-popover ddc-popover');
    $root->setAttribute('data-ecl-auto-init', 'Popover');

    // No ecl-button classes: popover.scss defines no rule for
    // .ecl-popover__toggle -- it is a behavioural hook -- and the button
    // classes would only import padding and min-height that are wrong for a
    // run of body text.
    $toggle = $dom->createElement('button');
    $toggle->setAttribute('class', 'ecl-popover__toggle ddc-popover__toggle');
    $toggle->setAttribute('type', 'button');
    $toggle->setAttribute('aria-controls', $id);
    $toggle->setAttribute('aria-expanded', 'false');
    $toggle->setAttribute('data-ecl-popover-toggle', '');
    while ($trigger->firstChild) {
      $toggle->appendChild($trigger->firstChild);
    }

    $container = $dom->createElement('span');
    $container->setAttribute('id', $id);
    $container->setAttribute('class', 'ecl-popover__container ddc-popover__container');
    $container->setAttribute('hidden', '');

    $scrollable = $dom->createElement('span');
    $scrollable->setAttribute('class', 'ecl-popover__scrollable ddc-popover__scrollable');

    if ($show_close) {
      $scrollable->appendChild($this->createCloseButton($dom));
    }

    $body = $dom->createElement('span');
    $body->setAttribute('class', 'ecl-popover__content ddc-popover__content');

    foreach (explode("\n", $content) as $i => $line) {
      if ($i > 0) {
        $body->appendChild($dom->createElement('br'));
      }
      $this->linkify($dom, $body, $line);
    }

    $scrollable->appendChild($body);
    $container->appendChild($scrollable);
    $root->appendChild($toggle);
    $root->appendChild($container);

    $trigger->parentNode->replaceChild($root, $trigger);
  }

  /**
   * Appends a line of plain text, turning http(s) URLs into links.
   *
   * Text and links are created as DOM nodes, so nothing in the line is ever
   * interpreted as markup.
   *
   * @param \DOMDocument $dom
   *   The document being rewritten.
   * @param \DOMElement $parent
   *   The node to append to.
   * @param string $line
   *   One line of plain text.
   */
  protected function linkify(\DOMDocument $dom, \DOMElement $parent, string $line): void {
    $offset = 0;

    preg_match_all(self::URL_PATTERN, $line, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[0] as [$url, $position]) {
      // Sentence punctuation after a pasted URL belongs to the sentence. A
      // closing parenthesis is kept only when the URL opened one itself, as
      // Wikipedia-style URLs do.
      $url = rtrim($url, '.,;:!?');
      while (str_ends_with($url, ')') && substr_count($url, '(') < substr_count($url, ')')) {
        $url = rtrim(substr($url, 0, -1), '.,;:!?');
      }

      if ($position > $offset) {
        $parent->appendChild($dom->createTextNode(substr($line, $offset, $position - $offset)));
      }

      $link = $dom->createElement('a');
      $link->setAttribute('href', $url);
      $link->setAttribute('class', 'ecl-link');
      $link->appendChild($dom->createTextNode($url));
      $parent->appendChild($link);

      $offset = $position + strlen($url);
    }

    if ($offset < strlen($line)) {
      $parent->appendChild($dom->createTextNode(substr($line, $offset)));
    }
  }

  /**
   * Builds a deterministic, cache-safe DOM id for a popover.
   *
   * Html::getUniqueId() must not be used here. Filter output is cached per
   * (text, format, langcode), so two independently cached fragments rendered
   * on the same page would each restart its counter and collide.
   *
   * @param string $trigger_text
   *   The trigger's text content.
   * @param string $content
   *   The popover content.
   * @param int $index
   *   The zero-based index of this popover within the text.
   *
   * @return string
   *   The element id.
   */
  protected function buildId(string $trigger_text, string $content, int $index): string {
    return 'ddc-popover-' . substr(hash('sha256', $trigger_text . "\0" . $content . "\0" . $index), 0, 12);
  }

  /**
   * Creates the popover close button.
   *
   * Icon-free: the glyph is drawn by css/popover.css, which avoids depending
   * on the ECL icon sprite path.
   *
   * @param \DOMDocument $dom
   *   The DOM document.
   *
   * @return \DOMElement
   *   The close button element.
   */
  protected function createCloseButton(\DOMDocument $dom): \DOMElement {
    $button = $dom->createElement('button');
    $button->setAttribute('class', 'ecl-popover__close ddc-popover__close');
    $button->setAttribute('type', 'button');
    $button->setAttribute('data-ecl-popover-close', '');

    $label = $dom->createElement('span', (string) $this->t('Close'));
    $label->setAttribute('class', 'ecl-u-sr-only');
    $button->appendChild($label);

    return $button;
  }

  /**
   * Replaces an element with its own children.
   *
   * @param \DOMElement $element
   *   The element to unwrap.
   */
  protected function unwrap(\DOMElement $element): void {
    $parent = $element->parentNode;

    if ($parent === NULL) {
      return;
    }

    while ($element->firstChild) {
      $parent->insertBefore($element->firstChild, $element);
    }

    $parent->removeChild($element);
  }

}
