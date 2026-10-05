/* eslint-disable import/no-extraneous-dependencies */

/**
 * The model attribute holding a popover's content.
 *
 * @type {string}
 */
export const ATTRIBUTE = 'ddcPopoverContent';

/**
 * The data-view attribute the Drupal filter reads.
 *
 * @type {string}
 */
export const DATA_ATTRIBUTE = 'data-ddc-popover-content';

/**
 * Returns the popover content applying to the current selection, if any.
 *
 * @param {module:engine/model/selection~Selection} selection
 *   The model selection.
 *
 * @return {string|undefined}
 *   The stored content, or undefined when the selection carries no popover.
 */
export function selectedContent(selection) {
  return selection.getAttribute(ATTRIBUTE);
}

/**
 * Normalises content for storage in an HTML attribute.
 *
 * Single line breaks are kept -- they are the one piece of formatting the
 * tooltip supports. Blank lines are not: _filter_autop() rewrites a run of two
 * or more newlines to "</p><p>" and would tear the attribute apart. Mirrors
 * FilterDdcPopover::toPlainText() so what the editor shows and what is stored
 * stay identical.
 *
 * @param {string} value
 *   The raw textarea value.
 *
 * @return {string}
 *   The normalised value.
 */
export function normalise(value) {
  return String(value)
    .replace(/\r\n?/g, '\n')
    // Horizontal whitespace only; \n is excluded by the character class.
    .replace(/[^\S\n]+/g, ' ')
    .replace(/ *\n */g, '\n')
    .replace(/\n{2,}/g, '\n')
    .trim();
}
