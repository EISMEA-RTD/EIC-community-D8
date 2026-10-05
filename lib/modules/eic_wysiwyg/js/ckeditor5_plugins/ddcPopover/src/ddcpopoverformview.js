/* eslint-disable import/no-extraneous-dependencies */

import { View, ButtonView, FocusCycler, submitHandler } from 'ckeditor5/src/ui';
import { FocusTracker, KeystrokeHandler } from 'ckeditor5/src/utils';

/**
 * The "enter the popup content" widget from the spec.
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

    this.labelView = new View(locale);
    this.labelView.setTemplate({
      tag: 'label',
      attributes: {
        class: ['ck', 'ck-label', 'ddc-popover-form__label'],
        for: 'ddc-popover-form__textarea',
      },
      children: [{ text: t('Tooltip content') }],
    });

    this.textareaView = new View(locale);
    this.textareaView.setTemplate({
      tag: 'textarea',
      attributes: {
        class: ['ck', 'ck-input', 'ddc-popover-form__textarea'],
        id: 'ddc-popover-form__textarea',
        rows: 4,
        placeholder: t('Secondary information shown in the tooltip'),
      },
    });

    // FocusCycler calls focus() on every focusable. A plain View has no such
    // method, so Tab-cycling would throw without this.
    this.textareaView.focus = () => {
      if (this.textareaView.element) {
        this.textareaView.element.focus();
      }
    };

    this.saveButtonView = this.createButton(t('Save'), 'ck-button-action ddc-popover-form__save');
    this.saveButtonView.type = 'submit';

    this.cancelButtonView = this.createButton(t('Cancel'), 'ddc-popover-form__cancel');
    this.cancelButtonView.delegate('execute').to(this, 'cancel');

    this._focusables = [this.textareaView, this.saveButtonView, this.cancelButtonView];
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
        class: ['ck', 'ddc-popover-form'],
        tabindex: '-1',
      },
      children: [
        this.labelView,
        this.textareaView,
        this.saveButtonView,
        this.cancelButtonView,
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

    this.textareaView.element.value = this._pendingValue;

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
    if (this.textareaView.element) {
      this.textareaView.element.focus();
    }
  }

  /**
   * The current textarea value.
   *
   * @return {string}
   *   The value.
   */
  get value() {
    return this.textareaView.element
      ? this.textareaView.element.value
      : this._pendingValue;
  }

  /**
   * Sets the textarea value.
   *
   * @param {string} value
   *   The value.
   */
  set value(value) {
    this._pendingValue = value || '';

    if (this.textareaView.element) {
      this.textareaView.element.value = this._pendingValue;
    }
  }
}
