/* eslint-disable import/no-extraneous-dependencies */
// cspell:ignore ddcpopoverediting ddcpopoverui

import { Plugin } from 'ckeditor5/src/core';
import DdcPopoverEditing from './ddcpopoverediting';
import DdcPopoverUi from './ddcpopoverui';

/**
 * Inline popover annotations.
 *
 * Lets an editor attach secondary information to a selected phrase. The data
 * is stored as a single attribute on an inline <span>; Drupal's
 * FilterDdcPopover expands that into ECL Popover markup at render time.
 */
export default class DdcPopover extends Plugin {
  /**
   * @inheritdoc
   */
  static get requires() {
    return [DdcPopoverEditing, DdcPopoverUi];
  }

  /**
   * @inheritdoc
   */
  static get pluginName() {
    return 'DdcPopover';
  }
}
