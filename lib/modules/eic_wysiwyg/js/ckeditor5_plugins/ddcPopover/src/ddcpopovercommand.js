/* eslint-disable import/no-extraneous-dependencies */

import { Command } from 'ckeditor5/src/core';
import { findAttributeRange } from 'ckeditor5/src/typing';
import { ATTRIBUTE, normalise } from './utils';

/**
 * Applies or updates popover content on the selection.
 */
export default class DdcPopoverCommand extends Command {
  /**
   * @inheritdoc
   */
  refresh() {
    const model = this.editor.model;
    const selection = model.document.selection;

    this.value = selection.getAttribute(ATTRIBUTE);
    this.isEnabled = model.schema.checkAttributeInSelection(
      selection,
      ATTRIBUTE,
    );
  }

  /**
   * Sets the popover content on the current selection.
   *
   * With a collapsed selection inside an existing popover the whole annotation
   * is updated, which is what makes "click the phrase, then edit" work. With a
   * non-collapsed selection the attribute is applied to each allowed range.
   *
   * @param {string} content
   *   The popover content.
   */
  execute(content) {
    const model = this.editor.model;
    const selection = model.document.selection;
    const value = normalise(content);

    if (!value) {
      this.editor.execute('ddcPopoverRemove');
      return;
    }

    model.change((writer) => {
      if (selection.isCollapsed) {
        const position = selection.getFirstPosition();

        // Only meaningful when the caret already sits in an annotation;
        // otherwise there is no text to attach the popover to.
        if (selection.hasAttribute(ATTRIBUTE)) {
          const range = findAttributeRange(
            position,
            ATTRIBUTE,
            selection.getAttribute(ATTRIBUTE),
            model,
          );
          writer.setAttribute(ATTRIBUTE, value, range);
          writer.setSelection(range);
        }

        // Stop the attribute leaking into whatever is typed next.
        writer.removeSelectionAttribute(ATTRIBUTE);
        return;
      }

      const ranges = model.schema.getValidRanges(
        selection.getRanges(),
        ATTRIBUTE,
      );

      Array.from(ranges).forEach((range) => {
        writer.setAttribute(ATTRIBUTE, value, range);
      });
    });
  }
}
