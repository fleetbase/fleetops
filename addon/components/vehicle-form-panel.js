import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class VehicleFormPanelComponent extends Component {
    /**
     * @service store
     */
    @service store;

    /**
     * @service store
     */
    @service fetch;

    /**
     * @service currentUser
     */
    @service currentUser;

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
     */
    @tracked context;

    /**
     * Indicates whether the component is in a loading state.
     * @type {boolean}
     */
    @tracked isLoading = false;

    /**
     * Status options for vehicles.
     * @type {Array}
     */
    @tracked vehicleStatusOptions = ['active', 'pending'];

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        this.vehicle = this.args.vehicle;
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
     * Saves the vehicle changes.
     *
     * @action
     * @returns {Promise<any>}
     */
    @action save() {
        const { vehicle } = this;

        this.loader.showLoader('.next-content-overlay-panel-container', { loadingMessage: 'Saving vehicle...', preserveTargetPosition: true });
        this.isLoading = true;

        contextComponentCallback(this, 'onBeforeSave', vehicle);

        try {
            return vehicle
                .save()
                .then((vehicle) => {
                    this.notifications.success(`Vehicle (${vehicle.displayName}) saved successfully.`);
                    contextComponentCallback(this, 'onAfterSave', vehicle);
                })
                .catch((error) => {
                    this.notifications.serverError(error);
                })
                .finally(() => {
                    this.loader.removeLoader('.next-content-overlay-panel-container ');
                    this.isLoading = false;
                });
        } catch (error) {
            this.loader.removeLoader('.next-content-overlay-panel-container ');
            this.isLoading = false;
        }
    }

    /**
     * Uploads a new photo for the vehicle.
     *
     * @param {File} file
     * @memberof DriverFormPanelComponent
     */
    @action onUploadNewPhoto(file) {
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/${this.currentUser.companyId}/vehicles/${this.vehicle.id}`,
                subject_uuid: this.vehicle.id,
                subject_type: 'vehicle',
                type: 'vehicle_photo',
            },
            (uploadedFile) => {
                this.vehicle.setProperties({
                    photo_uuid: uploadedFile.id,
                    photo_url: uploadedFile.url,
                    photo: uploadedFile,
                });
            }
        );
    }

    /**
     * View the details of the vehicle.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.vehicle);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.vehicle, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.vehicle);
    }
}
