/* eslint-disable import/no-extraneous-dependencies */

import {
  View,
  ButtonView,
  FocusCycler,
  FormHeaderView,
  FormRowView,
  LabeledFieldView,
  createLabeledTextarea,
  submitHandler,
} from 'ckeditor5/src/ui';
import { FocusTracker, KeystrokeHandler } from 'ckeditor5/src/utils';

/**
 * The "enter the popup content" widget from the spec.
 *
 * Built from the same parts as core's link form (a form header, form rows and
 * a labeled field) so it picks up CKEditor's own spacing and look instead of
 * imitating them in CSS.
 *
 * A textarea rather than a single-line input: popover content is a sentence or
 * two, and a one-line field makes that awkward to review. Enter is therefore
 * not bound to submit; Escape cancels, and the Save button commits.
 *
 * Buttons are text-labelled rather than icon-based on purpose. CKEditor 5 v45
 * changed how built-in icons are exported, and that churn has already broken
 * contrib plugins; text labels are immune to it and need no icon imports.
 */
export default class DdcPopoverFormView extends View {
  /**
   * @inheritdoc
   *
   * @param {module:utils/locale~Locale} locale
   *   The editor locale.
   */
  constructor(locale) {
    super(locale);

    const t = locale.t;

    this.focusTracker = new FocusTracker();
    this.keystrokes = new KeystrokeHandler();

    // A View has no .element until render(), and the balloon renders a view
    // only when it is added. Callers set `value` before that, so hold it here
    // and flush it in render().
    this._pendingValue = '';

    // Not focusable, so the FocusCycler below needs no knowledge of it.
    this.headerView = new FormHeaderView(locale, { label: t('Tooltip') });

    this.contentView = new LabeledFieldView(locale, createLabeledTextarea);
    this.contentView.label = t('Tooltip content');
    this.contentView.class = 'ck-labeled-field-view_full-width';
    this.contentView.fieldView.minRows = 4;
    this.contentView.fieldView.maxRows = 10;

    this.saveButtonView = this.createButton(t('Save'), 'ck-button-action ck-button-bold');
    this.saveButtonView.type = 'submit';

    this.cancelButtonView = this.createButton(t('Cancel'), 'ddc-popover-form__cancel');
    this.cancelButtonView.delegate('execute').to(this, 'cancel');

    this._focusables = [this.contentView, this.cancelButtonView, this.saveButtonView];
    this._focusCycler = new FocusCycler({
      focusables: this._focusables,
      focusTracker: this.focusTracker,
      keystrokeHandler: this.keystrokes,
      actions: {
        focusPrevious: 'shift + tab',
        focusNext: 'tab',
      },
    });

    this.setTemplate({
      tag: 'form',
      attributes: {
        class: ['ck', 'ck-form', 'ck-responsive-form', 'ddc-popover-form'],
        tabindex: '-1',
      },
      children: [
        this.headerView,
        new FormRowView(locale, {
          children: [this.contentView],
          class: ['ck-form__row_large-top-padding'],
        }),
        new FormRowView(locale, {
          children: [this.cancelButtonView, this.saveButtonView],
          class: [
            'ck-form__row_with-submit',
            'ck-form__row_large-top-padding',
            'ck-form__row_large-bottom-padding',
          ],
        }),
      ],
    });
  }

  /**
   * Creates a text-labelled button.
   *
   * @param {string} label
   *   The visible label.
   * @param {string} className
   *   An extra class.
   *
   * @return {module:ui/button/buttonview~ButtonView}
   *   The button.
   */
  createButton(label, className) {
    const button = new ButtonView(this.locale);

    button.set({
      label,
      withText: true,
      tooltip: false,
    });
    button.extendTemplate({
      attributes: {
        class: className,
      },
    });

    return button;
  }

  /**
   * @inheritdoc
   */
  render() {
    super.render();

    submitHandler({ view: this });

    this.contentView.fieldView.value = this._pendingValue;

    this._focusables.forEach((view) => {
      this.focusTracker.add(view.element);
    });

    this.keystrokes.listenTo(this.element);
  }

  /**
   * @inheritdoc
   */
  destroy() {
    super.destroy();
    this.focusTracker.destroy();
    this.keystrokes.destroy();
  }

  /**
   * Moves focus into the textarea.
   */
  focus() {
    this.contentView.focus();
  }

  /**
   * The current textarea value.
   *
   * @return {string}
   *   The value.
   */
  get value() {
    const { element } = this.contentView.fieldView;

    return element ? element.value : this._pendingValue;
  }

  /**
   * Sets the textarea value.
   *
   * @param {string} value
   *   The value.
   */
  set value(value) {
    this._pendingValue = value || '';
    this.contentView.fieldView.value = this._pendingValue;
  }
}
