import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { underscore } from '@ember/string';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class FleetFormPanelComponent extends Component {
    /**
     * @service store
     */
    @service store;

    /**
     * @service notifications
     */
    @service notifications;

    /**
     * @service hostRouter
     */
    @service hostRouter;

    /**
     * @service loader
     */
    @service loader;

    /**
     * @service contextPanel
     */
    @service contextPanel;

    /**
     * Overlay context.
     * @type {any}
     * @memberof FleetFormPanelComponent
     */
    @tracked context;

    /**
     * Indicates whether the component is in a loading state.
     * @type {boolean}
     * @memberof FleetFormPanelComponent
     */
    @tracked isLoading = false;

    /**
     * All possible order status options
     *
     * @var {String}
     * @memberof FleetFormPanelComponent
     */
    @tracked statusOptions = ['active', 'disabled', 'decommissioned'];

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        this.fleet = this.args.fleet;
        applyContextComponentArguments(this);
    }

    /**
     * Sets the overlay context.
     *
     * @action
     * @param {OverlayContextObject} overlayContext
     * @memberof FleetFormPanelComponent
     */
    @action setOverlayContext(overlayContext) {
        this.context = overlayContext;
        contextComponentCallback(this, 'onLoad', ...arguments);
    }

    /**
     * Saves the fleet changes.
     *
     * @action
     * @returns {Promise<any>}
     * @memberof FleetFormPanelComponent
     */
    @action save() {
        const { fleet } = this;

        this.loader.showLoader('.next-content-overlay-panel-container', { loadingMessage: 'Saving fleet...', preserveTargetPosition: true });
        this.isLoading = true;

        contextComponentCallback(this, 'onBeforeSave', fleet);

        try {
            return fleet
                .save()
                .then((fleet) => {
                    this.notifications.success(`Fleet (${fleet.name}) saved successfully.`);
                    contextComponentCallback(this, 'onAfterSave', fleet);
                })
                .catch((error) => {
                    this.notifications.serverError(error);
                })
                .finally(() => {
                    this.loader.removeLoader('.next-content-overlay-panel-container');
                    this.isLoading = false;
                });
        } catch (error) {
            this.loader.removeLoader('.next-content-overlay-panel-container');
            this.isLoading = false;
        }
    }

    /**
     * View the details of the fleet.
     *
     * @action
     * @memberof FleetFormPanelComponent
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.fleet);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.fleet, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     * @memberof FleetFormPanelComponent
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.fleet);
    }

    /**
     * Update relation on a model.
     *
     * @param {String} relation
     * @param {Model|null} value
     * @memberof FleetFormPanelComponent
     */
    @action updateRelationship(relation, value) {
        this.fleet.set(relation, value);

        if (!value) {
            this.fleet.set(underscore(relation) + '_uuid', null);
        }
    }
}
