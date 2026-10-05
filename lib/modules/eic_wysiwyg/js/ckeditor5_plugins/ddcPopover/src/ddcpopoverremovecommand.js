/* eslint-disable import/no-extraneous-dependencies */

import { Command } from 'ckeditor5/src/core';
import { findAttributeRange } from 'ckeditor5/src/typing';
import { ATTRIBUTE } from './utils';

/**
 * Removes the popover annotation, leaving the phrase as plain text.
 */
export default class DdcPopoverRemoveCommand extends Command {
  /**
   * @inheritdoc
   */
  refresh() {
    this.isEnabled = this.editor.model.document.selection.hasAttribute(ATTRIBUTE);
  }

  /**
   * @inheritdoc
   */
  execute() {
    const model = this.editor.model;
    const selection = model.document.selection;

    model.change((writer) => {
      const ranges = selection.isCollapsed
        ? [
            findAttributeRange(
              selection.getFirstPosition(),
              ATTRIBUTE,
              selection.getAttribute(ATTRIBUTE),
              model,
            ),
          ]
        : model.schema.getValidRanges(selection.getRanges(), ATTRIBUTE);

      Array.from(ranges).forEach((range) => {
        writer.removeAttribute(ATTRIBUTE, range);
      });

      writer.removeSelectionAttribute(ATTRIBUTE);
    });
  }
}
