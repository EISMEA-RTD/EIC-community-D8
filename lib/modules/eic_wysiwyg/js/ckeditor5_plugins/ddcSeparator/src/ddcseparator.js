/* eslint-disable import/no-extraneous-dependencies */

import { Plugin } from 'ckeditor5/src/core';
import { toWidget } from 'ckeditor5/src/widget';

/**
 * The class ECL's separator component styles.
 *
 * @type {string}
 */
export const SEPARATOR_CLASS = 'ecl-separator';

/**
 * Shows and stores the horizontal line as an ECL separator.
 *
 * Core's HorizontalLine downcasts the model element to a bare <hr>, both for
 * the stored data and for the editing view. This plugin re-registers both
 * downcasts at high priority so the stored markup is
 * <hr class="ecl-separator"> and the editor draws the same element, which
 * css/separator.ckeditor5.css then styles as ECL does on the front end.
 *
 * Upcast is untouched, so a bare <hr> already in saved text loads as a normal
 * horizontal line and gains the class the next time it is saved.
 */
export default class DdcSeparator extends Plugin {
  /**
   * @inheritdoc
   */
  static get requires() {
    // Plugins are resolved by name: the DLL build exposes no
    // ckeditor5/src/horizontal-line module to import the class from.
    return ['HorizontalLineEditing'];
  }

  /**
   * @inheritdoc
   */
  static get pluginName() {
    return 'DdcSeparator';
  }

  /**
   * @inheritdoc
   */
  init() {
    const { conversion, t } = this.editor;

    conversion.for('dataDowncast').elementToElement({
      model: 'horizontalLine',
      view: (modelElement, { writer }) =>
        writer.createEmptyElement('hr', { class: SEPARATOR_CLASS }),
      converterPriority: 'high',
    });

    // The same structure as core's editing downcast (a widget wrapper around
    // the <hr>, with the same custom properties other plugins look for), plus
    // the class.
    conversion.for('editingDowncast').elementToStructure({
      model: 'horizontalLine',
      view: (modelElement, { writer }) => {
        const hr = writer.createEmptyElement('hr', { class: SEPARATOR_CLASS });
        const viewWrapper = writer.createContainerElement('div', null, hr);

        writer.addClass('ck-horizontal-line', viewWrapper);
        writer.setCustomProperty('hr', true, viewWrapper);
        writer.setCustomProperty('horizontalLine', true, viewWrapper);

        return toWidget(viewWrapper, writer, { label: t('Horizontal line') });
      },
      converterPriority: 'high',
    });
  }
}
