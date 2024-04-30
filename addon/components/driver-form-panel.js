import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';
import contextComponentCallback from '@fleetbase/ember-core/utils/context-component-callback';
import applyContextComponentArguments from '@fleetbase/ember-core/utils/apply-context-component-arguments';

export default class DriverFormPanelComponent extends Component {
    /**
     * @service store
     */
    @service store;

    /**
     * @service fetch
     */
    @service fetch;

    /**
     * @service intl
     */
    @service intl;

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
     * @service modalsManager
     */
    @service modalsManager;

    /**
     * Overlay context.
     * @type {any}
     */
    @tracked context;

    /**
     * Status options for drivers.
     * @type {Array}
     */
    @tracked driverStatusOptions = ['active', 'pending'];

    /**
     * Indicates whether the component is in a loading state.
     * @type {boolean}
     */
    @tracked isLoading = false;

    /**
     * The coordinates input component instance.
     * @type {CoordinateInputComponent}
     */
    @tracked coordinatesInputComponent;

    /**
     * Action to create a new user quickly
     *
     * @memberof DriverFormPanelComponent
     */
    userAccountActionButtons = [
        {
            text: 'Create new user',
            icon: 'user-plus',
            size: 'xs',
            onClick: () => {
                const user = this.store.createRecord('user', {
                    status: 'pending',
                    type: 'user',
                });

                this.modalsManager.show('modals/user-form', {
                    title: 'Create a new user',
                    user,
                    uploadNewPhoto: (file) => {
                        this.fetch.uploadFile.perform(
                            file,
                            {
                                path: `uploads/${this.currentUser.companyId}/users/${user.slug}`,
                                key_uuid: user.id,
                                key_type: 'user',
                                type: 'user_photo',
                            },
                            (uploadedFile) => {
                                user.setProperties({
                                    avatar_uuid: uploadedFile.id,
                                    avatar_url: uploadedFile.url,
                                    avatar: uploadedFile,
                                });
                            }
                        );
                    },
                    confirm: (modal) => {
                        modal.startLoading();

                        return user
                            .save()
                            .then(() => {
                                this.notifications.success('New user created successfully!');
                            })
                            .catch((error) => {
                                this.notifications.serverError(error);
                            });
                    },
                });
            },
        },
    ];

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        this.driver = this.args.driver;
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
     * Saves the driver changes.
     *
     * @action
     * @returns {Promise<any>}
     */
    @action save() {
        const { driver } = this;

        this.loader.showLoader('.next-content-overlay-panel-container', { loadingMessage: 'Saving driver...', preserveTargetPosition: true });
        this.isLoading = true;

        contextComponentCallback(this, 'onBeforeSave', driver);

        return driver
            .save()
            .then((driver) => {
                this.notifications.success(this.intl.t('fleet-ops.component.driver-form-panel.success-message', { driverName: driver.name }));
                contextComponentCallback(this, 'onAfterSave', driver);
            })
            .catch((error) => {
                this.notifications.serverError(error);
            })
            .finally(() => {
                this.loader.removeLoader('.next-content-overlay-panel-container ');
                this.isLoading = false;
            });
    }

    /**
     * Uploads a new photo for the driver.
     *
     * @param {File} file
     * @memberof DriverFormPanelComponent
     */
    @action onUploadNewPhoto(file) {
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/${this.currentUser.companyId}/drivers/${this.driver.id}`,
                subject_uuid: this.driver.id,
                subject_type: 'fleet-ops:driver',
                type: 'driver_photo',
            },
            (uploadedFile) => {
                this.driver.setProperties({
                    photo_uuid: uploadedFile.id,
                    photo_url: uploadedFile.url,
                    photo: uploadedFile,
                });
            }
        );
    }

    /**
     * View the details of the driver.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.driver);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.driver, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.driver);
    }

    /**
     * Handles the selection from an autocomplete. Updates the place properties with the selected data.
     * If a coordinates input component is present, updates its coordinates too.
     *
     * @action
     * @param {Object} selected - The selected item from the autocomplete.
     * @param {Object} selected.location - The location data of the selected item.
     * @memberof DriverFormPanelComponent
     */
    @action onAutocomplete({ location }) {
        if (location) {
            this.driver.set('location', location);
            if (this.coordinatesInputComponent) {
                this.coordinatesInputComponent.updateCoordinates(location);
            }
        }
    }

    /**
     * Sets the coordinates input component.
     *
     * @action
     * @param {Object} coordinatesInputComponent - The coordinates input component to be set.
     * @memberof DriverFormPanelComponent
     */
    @action setCoordinatesInput(coordinatesInputComponent) {
        this.coordinatesInputComponent = coordinatesInputComponent;
    }

    /**
     * Updates the place coordinates with the given latitude and longitude.
     *
     * @action
     * @param {Object} coordinates - The latitude and longitude coordinates.
     * @param {number} coordinates.latitude - Latitude value.
     * @param {number} coordinates.longitude - Longitude value.
     * @memberof DriverFormPanelComponent
     */
    @action onCoordinatesChanged({ latitude, longitude }) {
        const location = new Point(longitude, latitude);
        this.driver.setProperties({ location });
    }
}
