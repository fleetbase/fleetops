import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import contextComponentCallback from '../utils/context-component-callback';
import applyContextComponentArguments from '../utils/apply-context-component-arguments';

export default class VendorFormPanelComponent extends Component {
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
     */
    @tracked context;

    /**
     * Indicates whether the component is in a loading state.
     * @type {boolean}
     */
    @tracked isLoading = false;

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        this.vendor = this.args.vendor;
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
     * Saves the vendor changes.
     *
     * @action
     * @returns {Promise<any>}
     */
    @action save() {
        const { vendor } = this;

        this.loader.showLoader('.next-content-overlay-panel-container', { loadingMessage: 'Saving vendor...', preserveTargetPosition: true });
        this.isLoading = true;

        contextComponentCallback(this, 'onBeforeSave', vendor);

        try {
            return vendor
                .save()
                .then((vendor) => {
                    this.notifications.success(`Vendor (${vendor.displayName}) saved successfully.`);
                    contextComponentCallback(this, 'onAfterSave', vendor);
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
     * View the details of the vendor.
     *
     * @action
     */
    @action onViewDetails() {
        const isActionOverrided = contextComponentCallback(this, 'onViewDetails', this.vendor);

        if (!isActionOverrided) {
            this.contextPanel.focus(this.vendor, 'viewing');
        }
    }

    /**
     * Handles cancel button press.
     *
     * @action
     * @returns {any}
     */
    @action onPressCancel() {
        return contextComponentCallback(this, 'onPressCancel', this.vendor);
    }

    @action onAction() {}
}