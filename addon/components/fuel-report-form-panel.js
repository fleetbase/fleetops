import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { task } from 'ember-concurrency';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';

export default class FuelReportFormPanelComponent extends Component {
    @service store;
    @service notifications;
    @service intl;
    @service hostRouter;
    @service contextPanel;

    /**
     * Overlay context.
     * @type {any}
     */
    @tracked context;

    /**
     * Fuel Report status
     * @type {Array}
     */
    @tracked statusOptions = ['draft', 'pending-approval', 'approved', 'rejected', 'revised', 'submitted', 'in-review', 'confirmed', 'processed', 'archived', 'cancelled'];

    /**
     * Permission needed to update or create record.
     *
     * @memberof FuelReportFormPanelComponent
     */
    @tracked savePermission;

    /**
     * The current controller if any.
     *
     * @memberof FuelReportFormPanelComponent
     */
    @tracked controller;

    /**
     * The coordinates input component context instance.
     *
     * @memberof FuelReportFormPanelComponent
     */
    @tracked coordinatesInputComponent;

    /**
     * Constructs the component and applies initial state.
     */
    constructor(owner, { fuelReport = null, controller }) {
        super(...arguments);
        this.fuelReport = fuelReport;
        this.controller = controller;
        this.savePermission = fuelReport && fuelReport.isNew ? 'fleet-ops create fuel-report' : 'fleet-ops update fuel-report';
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
        contextComponentCallback(this, 'onLoad', ...arguments);
    }

    /**
     * Task to save fuel report.
     *
     * @return {void}
     * @memberof FuelReportFormPanelComponent
     */
    @task *save() {
        contextComponentCallback(this, 'onBeforeSave', this.fuelReport);

        try {
            this.fuelReport = yield this.fuelReport.save();
        } catch (error) {
            this.notifications.serverError(error);
            return;
        }

        this.notifications.success(this.intl.t('fleet-ops.component.fuel-report-form-panel.success-message'));
        contextComponentCallback(this, 'onAfterSave', this.fuelReport);
    }

    /**
     * View the details of the fuel-report.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.fuelReport);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.fuelReport, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.fuelReport);
    }

    /**
     * Set the ruel report reporter
     *
     * @param {UserModel} user
     * @memberof FuelReportFormPanelComponent
     */
    @action setReporter(user) {
        this.fuelReport.set('reporter', user);
        this.fuelReport.set('reported_by_uuid', user.id);
    }

    /**
     * Sets the coordinates input component.
     *
     * @action
     * @param {Object} coordinatesInputComponent - The coordinates input component to be set.
     * @memberof PlaceFormPanelComponent
     */
    @action setCoordinatesInput(coordinatesInputComponent) {
        this.coordinatesInputComponent = coordinatesInputComponent;
    }

    /**
     * Handle autocomplete callback
     *
     * @param {AutocompleteEvent} { location }
     * @memberof VehicleFormPanelComponent
     */
    @action onAutocomplete({ location }) {
        if (location) {
            this.fuelReport.setProperties({ location });

            if (this.coordinatesInputComponent) {
                this.coordinatesInputComponent.updateCoordinates(location);
            }
        }
    }

    /**
     * Updates the Vehicle coordinates with the given latitude and longitude.
     *
     * @action
     * @param {Object} coordinates - The latitude and longitude coordinates.
     * @param {number} coordinates.latitude - Latitude value.
     * @param {number} coordinates.longitude - Longitude value.
     * @memberof PlaceFormPanelComponent
     */
    @action onCoordinatesChanged({ latitude, longitude }) {
        const location = new Point(longitude, latitude);

        this.fuelReport.setProperties({ location });
    }
}
