<?php

declare(strict_types=1);

namespace Drupal\eic_wysiwyg\Plugin\Filter;

use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Xss;
use Drupal\Core\Form\FormStateInterface;
use Drupal\filter\FilterProcessResult;
use Drupal\filter\Plugin\FilterBase;

/**
 * Renders inline annotations as ECL Tooltip triggers.
 *
 * Editors mark a phrase in the WYSIWYG and attach secondary information to it.
 * That is stored as a single inline element carrying one attribute:
 *
 * @code
 * <span data-ddc-popover-content="Some detail.">phrase</span>
 * @endcode
 *
 * A single inline element is a hard requirement, not a style choice: the
 * annotation lives inside body copy, and filter_autop and filter_htmlcorrector
 * would split the surrounding paragraph around any block-level markup.
 *
 * This filter rewrites that into an ECL Tooltip trigger. ECL's Tooltip
 * component (5.0.1+, shipped by oe_theme) is entirely JavaScript-driven: it
 * finds [data-ecl-tooltip] elements, builds the popup itself, and applies
 * role="tooltip", aria-describedby and aria-hidden. It assigns the content with
 * textContent -- never innerHTML -- so the popup can only ever contain plain
 * text, and is XSS-safe by construction.
 *
 * The trigger carries ECL's own .ecl-link class, so no styling ships with this
 * feature beyond a cursor rule and white-space handling in css/tooltip.css.
 *
 * Two deliberate trade-offs, both inherent to the component:
 * - Content is plain text, apart from line breaks. Links and emphasis are not
 *   possible; markup would render literally, so it is stripped here.
 * - ECL Tooltip is hover/focus driven and ECL's own guidance advises against
 *   it on touch-only devices. Triggers carry tabindex="0" so keyboard users can
 *   reach them, but touch users have no equivalent affordance. Use the ECL
 *   Popover component instead where that matters.
 *
 * The plugin id still says "popover" because it is referenced by saved text
 * format configuration; renaming it would break that config.
 *
 * WEIGHT: this filter must run LAST, after every other filter.
 *
 * The reason is _filter_autop(). It splits text only on
 * pre|script|style|object|iframe tags -- not on tags generally -- so it
 * rewrites newlines found inside attribute values too, turning a stored line
 * break into a literal "<br />" that later filters escape into visible text.
 * Encoding the break to dodge autop does not work either: a character
 * reference is decoded straight back to a newline by any filter that does an
 * Html::load()/Html::serialize() round-trip first, and eic_filter_div_tables
 * does exactly that whenever the text contains a table.
 *
 * Running last sidesteps all of it. By the time this filter sees the stored
 * attribute autop has already been through it, and strip_tags() in
 * toPlainText() removes the injected "<br />" while leaving the newline. Since
 * nothing runs afterwards, the newline this filter emits reaches the browser
 * intact, where css/tooltip.css renders it via white-space: pre-line.
 *
 * @Filter(
 *   id = "filter_ddc_popover",
 *   title = @Translation("ECL inline tooltip"),
 *   description = @Translation("Renders inline annotations created in the editor as ECL Tooltip triggers. Must be the LAST filter in the processing order."),
 *   type = Drupal\filter\Plugin\FilterInterface::TYPE_TRANSFORM_IRREVERSIBLE,
 *   weight = 50,
 *   settings = {
 *     "show_close_button" = TRUE,
 *     "enable_hover" = FALSE,
 *     "inverted" = FALSE
 *   }
 * )
 */
class FilterDdcPopover extends FilterBase {

  /**
   * The attribute carrying the tooltip content on the stored markup.
   */
  public const CONTENT_ATTRIBUTE = 'data-ddc-popover-content';

  /**
   * {@inheritdoc}
   */
  public function settingsForm(array $form, FormStateInterface $form_state) {
    $form['inverted'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use the inverted (dark) tooltip style'),
      '#default_value' => $this->settings['inverted'] ?? FALSE,
      '#description' => $this->t('Renders the ECL tooltip on a dark background.'),
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
    $attribute = ($this->settings['inverted'] ?? FALSE)
      ? 'data-ecl-tooltip-inverted'
      : 'data-ecl-tooltip';

    // Snapshot the node list: the tree is mutated while iterating.
    $triggers = iterator_to_array($xpath->query('//span[@' . self::CONTENT_ATTRIBUTE . ']'));

    $rendered = FALSE;

    /** @var \DOMElement $trigger */
    foreach ($triggers as $trigger) {
      $raw = $trigger->getAttribute(self::CONTENT_ATTRIBUTE);
      $trigger->removeAttribute(self::CONTENT_ATTRIBUTE);

      // A trigger inside a link or button would put a focusable element inside
      // another one, and ECL's guidance is explicit that tooltips do not go on
      // interactive content.
      if ($xpath->query('ancestor::a|ancestor::button', $trigger)->count() > 0) {
        $this->unwrap($trigger);
        continue;
      }

      $content = $this->toPlainText($raw);

      // An empty tooltip is worse than none: it marks up a phrase that reveals
      // nothing.
      if ($content === '') {
        $this->unwrap($trigger);
        continue;
      }

      $this->renderTooltip($trigger, $attribute, $content);
      $rendered = TRUE;
    }

    $result->setProcessedText(Html::serialize($dom));

    if ($rendered) {
      $result->setAttachments(['library' => ['eic_wysiwyg/tooltip']]);
    }

    return $result;
  }

  /**
   * Reduces stored content to plain text, keeping single line breaks.
   *
   * ECL assigns the popup content with textContent, so markup would be shown
   * literally rather than rendered. Xss::filter() runs first so a malformed or
   * nested tag cannot survive strip_tags().
   *
   * strip_tags() is also what repairs autop's damage: because this filter runs
   * last, a stored newline has already been rewritten to "<br />\n" by the time
   * it arrives, and removing the tag leaves the newline behind.
   *
   * Line breaks are the one piece of formatting kept, so an editor can separate
   * a definition from a note. Everything else collapses: runs of spaces and
   * tabs become one space, and blank lines collapse to a single break.
   *
   * @param string $raw
   *   The stored content.
   *
   * @return string
   *   Plain text with single line breaks, possibly empty.
   */
  protected function toPlainText(string $raw): string {
    $text = strip_tags(Xss::filter($raw, []));

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
   * Turns a stored trigger into an ECL Tooltip trigger.
   *
   * ECL's Tooltip JS creates the popup element itself, so all that is emitted
   * here is the trigger and its content attribute.
   *
   * @param \DOMElement $trigger
   *   The trigger element, rewritten in place.
   * @param string $attribute
   *   Either data-ecl-tooltip or data-ecl-tooltip-inverted.
   * @param string $content
   *   Plain-text tooltip content.
   */
  protected function renderTooltip(\DOMElement $trigger, string $attribute, string $content): void {
    // ECL's own inline link styling -- the default variant, no modifier.
    $classes = array_filter(['ecl-link', $trigger->getAttribute('class')]);
    $trigger->setAttribute('class', implode(' ', $classes));
    $trigger->setAttribute($attribute, $content);
    // ECL binds hover and focus. A <span> is not focusable, so without this
    // keyboard users could never reach the content.
    $trigger->setAttribute('tabindex', '0');
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
