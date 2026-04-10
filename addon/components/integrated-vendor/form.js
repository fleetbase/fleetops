import Component from '@glimmer/component';
import { tracked } from '@glimmer/tracking';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

export default class IntegratedVendorFormComponent extends Component {
    @service store;
    @tracked showAdvancedOptions = false;

    /**
     * The currently selected shipper client Vendor for broker-scoped
     * credential records. Tracked so the <ModelSelect> reflects the
     * current state. Loaded from the store on first render when
     * shipper_client_uuid is already set on the resource (edit flow).
     */
    @tracked selectedShipperClient = null;

    constructor() {
        super(...arguments);
        this._loadExistingShipperClient();
    }

    /**
     * If the resource already has a shipper_client_uuid (e.g. editing
     * an existing IntegratedVendor), resolve the Vendor model from the
     * store so the <ModelSelect> renders the name rather than showing
     * "empty".
     */
    async _loadExistingShipperClient() {
        const uuid = this.args.resource?.shipper_client_uuid;
        if (!uuid) {
            return;
        }
        try {
            // peekRecord first (might already be in the store from a previous load)
            let vendor = this.store.peekRecord('vendor', uuid);
            if (!vendor) {
                vendor = await this.store.findRecord('vendor', uuid);
            }
            this.selectedShipperClient = vendor;
        } catch {
            // Vendor may have been deleted or is inaccessible — leave the
            // selector empty; the raw UUID on the resource is preserved.
        }
    }

    @action toggleAdvancedOptions() {
        this.showAdvancedOptions = !this.showAdvancedOptions;
    }

    /**
     * Called by the <ModelSelect> when the user picks a Vendor or clears
     * the selection. Sets the raw UUID on the resource (which the API
     * serializer will persist) and updates the tracked property so the
     * dropdown reflects the change.
     */
    @action setShipperClient(vendor) {
        this.selectedShipperClient = vendor;
        if (this.args.resource) {
            this.args.resource.shipper_client_uuid = vendor?.id ?? null;
        }
    }
}
