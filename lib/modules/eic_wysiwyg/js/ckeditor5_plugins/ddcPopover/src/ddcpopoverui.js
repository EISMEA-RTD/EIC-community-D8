/* eslint-disable import/no-extraneous-dependencies */
// cspell:ignore ddcpopoverformview ddcpopoveractionsview

import { Plugin } from 'ckeditor5/src/core';
import { ButtonView, ContextualBalloon, clickOutsideHandler } from 'ckeditor5/src/ui';
import { ClickObserver } from 'ckeditor5/src/engine';
import { findAttributeRange } from 'ckeditor5/src/typing';
import DdcPopoverFormView from './ddcpopoverformview';
import DdcPopoverActionsView from './ddcpopoveractionsview';
import { ATTRIBUTE } from './utils';
import popoverIcon from '../../../../icons/popover.svg';

/**
 * Toolbar button and balloon orchestration, modelled on core's LinkUI.
 */
export default class DdcPopoverUi extends Plugin {
  /**
   * @inheritdoc
   */
  static get requires() {
    return [ContextualBalloon];
  }

  /**
   * @inheritdoc
   */
  static get pluginName() {
    return 'DdcPopoverUi';
  }

  /**
   * @inheritdoc
   */
  init() {
    const editor = this.editor;

    editor.editing.view.addObserver(ClickObserver);

    this._balloon = editor.plugins.get(ContextualBalloon);
    this._formView = this._createFormView();
    this._actionsView = this._createActionsView();

    this._createToolbarButton();
    this._enableUserBalloonInteractions();
  }

  /**
   * @inheritdoc
   */
  destroy() {
    super.destroy();
    this._formView.destroy();
    this._actionsView.destroy();
  }

  /**
   * Registers the toolbar button.
   */
  _createToolbarButton() {
    const editor = this.editor;
    const command = editor.commands.get('ddcPopover');

    editor.ui.componentFactory.add('ddcPopover', (locale) => {
      const button = new ButtonView(locale);

      button.set({
        label: locale.t('Tooltip'),
        icon: popoverIcon,
        tooltip: true,
      });

      button.bind('isEnabled').to(command, 'isEnabled');
      button.bind('isOn').to(command, 'value', (value) => !!value);

      this.listenTo(button, 'execute', () => {
        // Caret inside an existing annotation: show what is there rather than
        // silently starting a new one.
        if (this._selectionHasPopover()) {
          this._showUi(this._actionsView);
          return;
        }
        this._showUi(this._formView);
      });

      return button;
    });
  }

  /**
   * Builds the content-entry balloon.
   *
   * @return {DdcPopoverFormView}
   *   The form view.
   */
  _createFormView() {
    const editor = this.editor;
    const form = new DdcPopoverFormView(editor.locale);

    this.listenTo(form, 'submit', () => {
      editor.execute('ddcPopover', form.value);
      this._hideUi();
    });

    this.listenTo(form, 'cancel', () => this._hideUi());

    form.keystrokes.set('Esc', (data, cancel) => {
      this._hideUi();
      cancel();
    });

    return form;
  }

  /**
   * Builds the edit/remove balloon.
   *
   * @return {DdcPopoverActionsView}
   *   The actions view.
   */
  _createActionsView() {
    const editor = this.editor;
    const actions = new DdcPopoverActionsView(editor.locale);

    this.listenTo(actions, 'edit', () => {
      this._removeFromBalloon(actions);
      this._showUi(this._formView);
    });

    this.listenTo(actions, 'remove', () => {
      editor.execute('ddcPopoverRemove');
      this._hideUi();
    });

    actions.keystrokes.set('Esc', (data, cancel) => {
      this._hideUi();
      cancel();
    });

    return actions;
  }

  /**
   * Shows the actions balloon when the caret enters an annotation, and closes
   * the balloon on click-outside.
   */
  _enableUserBalloonInteractions() {
    const editor = this.editor;

    this.listenTo(editor.editing.view.document, 'click', () => {
      if (this._selectionHasPopover()) {
        this._showUi(this._actionsView);
      }
    });

    clickOutsideHandler({
      emitter: this._formView,
      activator: () => this._balloon.hasView(this._formView) || this._balloon.hasView(this._actionsView),
      contextElements: [this._balloon.view.element],
      callback: () => this._hideUi(),
    });
  }

  /**
   * Whether the selection currently carries a popover annotation.
   *
   * @return {boolean}
   *   TRUE when it does.
   */
  _selectionHasPopover() {
    return this.editor.model.document.selection.hasAttribute(ATTRIBUTE);
  }

  /**
   * Adds a view to the balloon, positioned over the annotated phrase.
   *
   * @param {module:ui/view~View} view
   *   The view to show.
   */
  _showUi(view) {
    const editor = this.editor;
    const content = editor.model.document.selection.getAttribute(ATTRIBUTE) || '';

    // Close whichever view is already open before swapping in the other.
    [this._formView, this._actionsView]
      .filter((candidate) => candidate !== view && this._balloon.hasView(candidate))
      .forEach((candidate) => this._removeFromBalloon(candidate));

    // Add first. ContextualBalloon renders a view when it is added, and the
    // form view writes straight to its textarea element, which does not exist
    // before that.
    if (!this._balloon.hasView(view)) {
      this._balloon.add({
        view,
        position: this._getBalloonPositionData(),
      });
    }

    if (view === this._formView) {
      view.value = content;
    }
    else {
      view.content = content;
    }

    view.focus();
  }

  /**
   * Removes a view from the balloon if present.
   *
   * @param {module:ui/view~View} view
   *   The view to remove.
   */
  _removeFromBalloon(view) {
    if (this._balloon.hasView(view)) {
      this._balloon.remove(view);
    }
  }

  /**
   * Hides the balloon and returns focus to the editing area.
   */
  _hideUi() {
    this._removeFromBalloon(this._formView);
    this._removeFromBalloon(this._actionsView);
    this.editor.editing.view.focus();
  }

  /**
   * Positions the balloon over the whole annotation, not just the caret.
   *
   * @return {object}
   *   Balloon position data.
   */
  _getBalloonPositionData() {
    const editor = this.editor;
    const view = editor.editing.view;
    const model = editor.model;
    const selection = model.document.selection;
    let target;

    if (this._selectionHasPopover() && selection.isCollapsed) {
      const modelRange = findAttributeRange(
        selection.getFirstPosition(),
        ATTRIBUTE,
        selection.getAttribute(ATTRIBUTE),
        model,
      );
      const viewRange = editor.editing.mapper.toViewRange(modelRange);
      target = view.domConverter.viewRangeToDom(viewRange);
    }
    else {
      target = () => view.domConverter.viewRangeToDom(
        view.document.selection.getFirstRange(),
      );
    }

    return { target };
  }
}
