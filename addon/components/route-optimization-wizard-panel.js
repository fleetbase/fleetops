import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';

export default class RouteOptimizationWizardPanelComponent extends Component {
    @service universe;
    @tracked context;

    /**
     * Constructs the component and applies initial state.
     */
    constructor(owner, { controller }) {
        super(...arguments);
        this.controller = controller;
        applyContextComponentArguments(this);
    }

    /**
     * Sets the overlay context.
     *
     * @action
     * @param {OverlayContextObject} overlayContext
     */
    @action setOverlayContext(overlayContext) {
        this.context = overlayContext;
        // hide sidebar
        if (this.universe.sidebarContext) {
            this.universe.sidebarContext.hide();
        }
        contextComponentCallback(this, 'onLoad', ...arguments);
    }

    /**
     * Handles the cancel action.
     *
     * @method
     * @action
     * @returns {Boolean} Indicates whether the cancel action was overridden.
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel');
    }
}
