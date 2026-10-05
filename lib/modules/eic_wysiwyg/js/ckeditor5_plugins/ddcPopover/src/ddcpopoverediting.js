/* eslint-disable import/no-extraneous-dependencies */
// cspell:ignore ddcpopovercommand ddcpopoverremovecommand

import { Plugin } from 'ckeditor5/src/core';
import DdcPopoverCommand from './ddcpopovercommand';
import DdcPopoverRemoveCommand from './ddcpopoverremovecommand';
import { ATTRIBUTE, DATA_ATTRIBUTE } from './utils';

/**
 * Schema, conversion and commands for inline popover annotations.
 *
 * Modelled as a text attribute rather than an element, the same shape core's
 * Link plugin uses. The trigger therefore stays ordinary editable text:
 * typing inside it extends the annotation, and selection, undo and clipboard
 * behaviour all come for free.
 */
export default class DdcPopoverEditing extends Plugin {
  /**
   * @inheritdoc
   */
  static get pluginName() {
    return 'DdcPopoverEditing';
  }

  /**
   * @inheritdoc
   */
  init() {
    const editor = this.editor;

    editor.model.schema.extend('$text', { allowAttributes: ATTRIBUTE });

    editor.conversion.for('upcast').elementToAttribute({
      view: {
        name: 'span',
        attributes: {
          [DATA_ATTRIBUTE]: true,
        },
      },
      model: {
        key: ATTRIBUTE,
        value: (viewElement) => viewElement.getAttribute(DATA_ATTRIBUTE),
      },
    });

    // Data downcast: exactly what FilterDdcPopover expects to find -- one
    // <span> carrying one attribute. Priority 5 matches core's Link, so this
    // nests predictably with bold/italic attribute elements.
    editor.conversion.for('dataDowncast').attributeToElement({
      model: ATTRIBUTE,
      view: (value, { writer }) =>
        writer.createAttributeElement(
          'span',
          { [DATA_ATTRIBUTE]: value },
          { priority: 5 },
        ),
    });

    // Editing downcast: adds a class so the editing view can show the
    // annotation. Styled by css/popover.ckeditor5.css.
    editor.conversion.for('editingDowncast').attributeToElement({
      model: ATTRIBUTE,
      view: (value, { writer }) =>
        writer.createAttributeElement(
          'span',
          {
            [DATA_ATTRIBUTE]: value,
            class: 'ddc-popover-trigger',
          },
          { priority: 5 },
        ),
    });

    editor.commands.add('ddcPopover', new DdcPopoverCommand(editor));
    editor.commands.add('ddcPopoverRemove', new DdcPopoverRemoveCommand(editor));
  }
}
