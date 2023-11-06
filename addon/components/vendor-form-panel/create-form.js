import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { inject as service } from '@ember/service';
import { action } from '@ember/object';
import { isArray } from '@ember/array';
import apiUrl from '@fleetbase/ember-core/utils/api-url';
import contextComponentCallback from '../../utils/context-component-callback';

export default class VendorFormPanelCreateFormComponent extends Component {
    /**
     * @service store
     */
    @service store;

    /**
     * @service fetch
     */
    @service fetch;

    /**
     * @service contextPanel
     */
    @service contextPanel;
    /**
     * State of whether editing credentials is enabled.
     * @type {Boolean}
     */
    @tracked isEditingCredentials = false;

    /**
     * State of whether to show advanced options for integrated vendor.
     * @type {Boolean}
     */
    @tracked showAdvancedOptions = false;

    /**
     * The selectable types of vendors.
     * @type {Array}
     */
    @tracked vendorTypes = [
        { label: 'Choose a integrated vendor', value: 'integrated-vendor' },
        { label: 'Create a custom vendor', value: 'vendor' },
    ];

    /**
     * The selected type of vendor being created or edited.
     * @type {String}
     */
    @tracked selectedVendorType = this.vendorTypes[1];

    /**
     * The supported integrated vendors.
     * @type {Array}
     */
    @tracked supportedIntegratedVendors = [];

    /**
     * The selected integrated vendor provider.
     * @type {Object}
     */
    @tracked selectedIntegratedVendor;

    constructor() {
        super(...arguments);
        this.vendor = this.args.vendor;
        this.fetchSupportedIntegratedVendors();
    }

    @action toggleCredentialsReset() {
        if (this.isEditingCredentials) {
            this.isEditingCredentials = false;
        } else {
            this.isEditingCredentials = true;
        }
    }

    @action toggleAdvancedOptions() {
        if (this.showAdvancedOptions) {
            this.showAdvancedOptions = false;
        } else {
            this.showAdvancedOptions = true;
        }
    }

    @action onSelectVendorType(selectedVendorType) {
        this.selectedVendorType = selectedVendorType;
    }

    @action onSelectIntegratedVendor(integratedVendor) {
        this.selectedIntegratedVendor = integratedVendor;
        const { credential_params, option_params } = integratedVendor;

        // create credentials object
        const credentials = {};
        if (isArray(integratedVendor.credential_params)) {
            for (let i = 0; i < integratedVendor.credential_params.length; i++) {
                const param = integratedVendor.credential_params.objectAt(i);
                credentials[param] = null;
            }
        }

        // create options object
        const options = {};
        if (isArray(integratedVendor.option_params)) {
            for (let i = 0; i < integratedVendor.option_params.length; i++) {
                const param = integratedVendor.option_params.objectAt(i);
                options[param.key] = null;
            }
        }

        const vendor = this.store.createRecord('integrated-vendor', {
            provider: integratedVendor.code,
            webhook_url: apiUrl(`listeners/${integratedVendor.code}`),
            credentials: {},
            options: {},
            credential_params,
            option_params,
        });

        this.vendor = vendor;

        // trigger callback
        contextComponentCallback(this, 'onVendorChanged', vendor);
    }

    @action selectVendorAddress(place) {
        this.vendor.place = place;
        this.vendor.place_uuid = place.id;
    }

    @action async editAddress() {
        let place;

        if (this.vendor.has_place) {
            place = await this.vendor.place;
        } else {
            place = this.store.createRecord('place');
        }

        return this.contextPanel.focus(place, 'editing', {
            onAfterSave: (place) => {
                this.vendor.place = place;
                this.contextPanel.clear();
            },
        });
    }

    /**
     * Fetches the supported integrated vendors.
     *
     * @returns {Promise}
     */
    fetchSupportedIntegratedVendors() {
        return this.fetch.get('integrated-vendors/supported').then((supportedIntegratedVendors) => {
            this.supportedIntegratedVendors = supportedIntegratedVendors;
        });
    }
}
