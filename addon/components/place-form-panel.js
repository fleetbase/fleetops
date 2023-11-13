import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isBlank } from '@ember/utils';
import Point from '@fleetbase/fleetops-data/utils/geojson/point';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class PlaceFormPanelComponent extends Component {
    /**
     * @service store
     */
    @service store;

    /**
     * @service fetch
     */
    @service fetch;

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
     * The coordinates input component instance.
     * @type {CoordinateInputComponent}
     */
    @tracked coordinatesInputComponent;

    /**
     * All possible place types
     *
     * @var {String}
     */
    @tracked placeTypes = ['place', 'customer'];

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        this.place = this.args.place;
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
     * Saves the place changes.
     *
     * @action
     * @returns {Promise<any>}
     */
    @action save() {
        const { place } = this;

        this.loader.showLoader('.next-content-overlay-panel-container', { loadingMessage: 'Saving place...', preserveTargetPosition: true });
        this.isLoading = true;

        contextComponentCallback(this, 'onBeforeSave', place);

        try {
            return place
                .save()
                .then((place) => {
                    this.notifications.success(`place (${place.address}) saved successfully.`);
                    contextComponentCallback(this, 'onAfterSave', place);
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
     * View the details of the place.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.place);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.place, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.place);
    }

    /**
     * Handles the selection from an autocomplete. Updates the place properties with the selected data.
     * If a coordinates input component is present, updates its coordinates too.
     *
     * @action
     * @param {Object} selected - The selected item from the autocomplete.
     * @param {Object} selected.location - The location data of the selected item.
     * @memberof PlaceFormPanelComponent
     */
    @action onAutocomplete(selected) {
        this.place.setProperties({ ...selected });

        if (this.coordinatesInputComponent) {
            this.coordinatesInputComponent.updateCoordinates(selected.location);
        }
    }

    /**
     * Performs reverse geocoding given latitude and longitude. Updates place properties with the geocoding result.
     *
     * @action
     * @param {Object} coordinates - The latitude and longitude coordinates.
     * @param {number} coordinates.latitude - Latitude value.
     * @param {number} coordinates.longitude - Longitude value.
     * @returns {Promise} A promise that resolves with the reverse geocoding result.
     * @memberof PlaceFormPanelComponent
     */
    @action onReverseGeocode({ latitude, longitude }) {
        return this.fetch.get('geocoder/reverse', { coordinates: [latitude, longitude].join(','), single: true }).then((result) => {
            if (isBlank(result)) {
                return;
            }

            this.place.setProperties({ ...result });
        });
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
     * Updates the place coordinates with the given latitude and longitude.
     *
     * @action
     * @param {Object} coordinates - The latitude and longitude coordinates.
     * @param {number} coordinates.latitude - Latitude value.
     * @param {number} coordinates.longitude - Longitude value.
     * @memberof PlaceFormPanelComponent
     */
    @action updatePlaceCoordinates({ latitude, longitude }) {
        const location = new Point(longitude, latitude);

        this.place.setProperties({ location });
    }
}
