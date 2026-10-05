/* eslint-disable import/no-extraneous-dependencies */

import { View, ButtonView, FocusCycler } from 'ckeditor5/src/ui';
import { FocusTracker, KeystrokeHandler } from 'ckeditor5/src/utils';

/**
 * Shown when the caret lands inside an existing popover.
 *
 * Previews the stored content and offers Edit and Remove, mirroring how core's
 * LinkUI surfaces an existing link.
 */
export default class DdcPopoverActionsView extends View {
  /**
   * @inheritdoc
   *
   * @param {module:utils/locale~Locale} locale
   *   The editor locale.
   */
  constructor(locale) {
    super(locale);

    const t = locale.t;

    this.set('content', '');

    this.focusTracker = new FocusTracker();
    this.keystrokes = new KeystrokeHandler();

    this.previewView = new View(locale);
    this.previewView.setTemplate({
      tag: 'span',
      attributes: {
        class: ['ck', 'ddc-popover-actions__preview'],
        title: this.bindTemplate.to('content'),
      },
      children: [
        {
          text: this.bindTemplate.to('content', (value) =>
            value && value.length > 60 ? `${value.slice(0, 60)}…` : value,
          ),
        },
      ],
    });

    this.editButtonView = this.createButton(t('Edit'));
    this.editButtonView.delegate('execute').to(this, 'edit');

    this.removeButtonView = this.createButton(t('Remove'));
    this.removeButtonView.delegate('execute').to(this, 'remove');

    this._focusables = [this.editButtonView, this.removeButtonView];
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
      tag: 'div',
      attributes: {
        class: ['ck', 'ddc-popover-actions'],
        tabindex: '-1',
      },
      children: [this.previewView, this.editButtonView, this.removeButtonView],
    });
  }

  /**
   * Creates a text-labelled button.
   *
   * @param {string} label
   *   The visible label.
   *
   * @return {module:ui/button/buttonview~ButtonView}
   *   The button.
   */
  createButton(label) {
    const button = new ButtonView(this.locale);

    button.set({
      label,
      withText: true,
      tooltip: false,
    });

    return button;
  }

  /**
   * @inheritdoc
   */
  render() {
    super.render();

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
   * Moves focus to the Edit button.
   */
  focus() {
    this.editButtonView.focus();
  }
}
