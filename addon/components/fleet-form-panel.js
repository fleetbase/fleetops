import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { underscore } from '@ember/string';
import { task } from 'ember-concurrency';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';

export default class FleetFormPanelComponent extends Component {
    @service store;
    @service notifications;
    @service hostRouter;
    @service intl;
    @service contextPanel;

    /**
     * Overlay context.
     * @type {any}
     * @memberof FleetFormPanelComponent
     */
    @tracked context;

    /**
     * All possible order status options
     *
     * @var {String}
     * @memberof FleetFormPanelComponent
     */
    @tracked statusOptions = ['active', 'disabled', 'decommissioned'];

    /**
     * Permission needed to update or create record.
     *
     * @memberof FleetFormPanelComponent
     */
    @tracked savePermission;

    /**
     * The current controller if any.
     *
     * @memberof FleetFormPanelComponent
     */
    @tracked controller;

    /**
     * Constructs the component and applies initial state.
     */
    constructor(owner, { fleet = null, controller }) {
        super(...arguments);
        this.fleet = fleet;
        this.controller = controller;
        this.savePermission = fleet && fleet.isNew ? 'fleet-ops create fleet' : 'fleet-ops update fleet';
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
     * Task to save fleet.
     *
     * @return {void}
     * @memberof FleetFormPanelComponent
     */
    @task *save() {
        contextComponentCallback(this, 'onBeforeSave', this.fleet);

        try {
            this.fleet = yield this.fleet.save();
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }

        this.notifications.success(this.intl.t('fleet-ops.component.fleet-form-panel.success-message', { fleetName: this.fleet.name }));
        contextComponentCallback(this, 'onAfterSave', this.fleet);
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
