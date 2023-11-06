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
     * @service fetch
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
     * The users vendor instance.
     * @type {VendorModel|IntegratedVendorModel}
     */
    @tracked vendor;

    /**
     * Specific types of vendors which can be set as the type.
     *
     * @memberof VendorFormPanelComponent
     */
    @tracked vendorTypeOptions = [
        'carrier-trucking-company',
        'carrier-shipping-line',
        'carrier-air-cargo',
        'carrier-rail',
        'freight-forwarder',
        'warehouse-provider',
        'broker',
        'maintenance-repair-facility',
        'fuel-station',
        'tire-service-provider',
        'equipment-rental',
        'customs-broker',
        'third-party-logistics-provider',
        'technology-provider',
        'consultant',
        'insurance-provider',
        'last-mile-delivery-service',
        'packing-crating-service',
        'intermodal-operator',
        'drayage-service-provider',
        'cold-chain-provider',
        'hazardous-material-specialist',
        'parcel-courier',
        'bulk-material-handler',
        'towing-service',
        'cargo-inspector',
        'pallet-provider',
        'label-packaging-material-supplier',
        'driver-leasing-service',
        'vehicle-manufacturer',
        'vehicle-dealership',
        'parts-supplier',
        'logistics-association',
        'regulatory-body',
        'training-certification-provider',
        'general-vendor',
        'ecommerce',
    ];

    /**
     * Applicable status options for vendor.
     *
     * @memberof VendorFormPanelComponent
     */
    @tracked vendorStatusOptions = ['pending', 'active', 'do-not-contact', 'prospective', 'archived'];

    /**
     * Constructs the component and applies initial state.
     */
    constructor() {
        super(...arguments);
        this.vendor = this.args.vendor;
        this.isEditing = typeof this.vendor.id === 'string';
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
     * Uploads a new logo for the vendor.
     *
     * @param {File} file
     * @memberof DriverFormPanelComponent
     */
    @action onUploadNewPhoto(file) {
        this.fetch.uploadFile.perform(
            file,
            {
                path: `uploads/${this.currentUser.companyId}/vendors/${this.vendor.id}`,
                subject_uuid: this.vendor.id,
                subject_type: 'vendor',
                type: 'vendor_logo',
            },
            (uploadedFile) => {
                this.vendor.setProperties({
                    logo_uuid: uploadedFile.id,
                    logo_url: uploadedFile.url,
                    logo: uploadedFile,
                });
            }
        );
    }

    /**
     * Handle when vendor changed.
     *
     * @param {VendorModel} vendor
     * @memberof VendorFormPanelComponent
     */
    @action onVendorChanged(vendor) {
        this.vendor = vendor;
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
}
