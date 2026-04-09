import Component from '@glimmer/component';
import { action } from '@ember/object';
import { inject as service } from '@ember/service';

/**
 * CarrierOnboardingPanel
 *
 * Onboarding surface that nudges new operators toward ParcelPath as the
 * default ParcelPath/UPS/USPS integration path while still exposing the
 * direct UPS and USPS bridges for shippers with their own carrier
 * contracts. Uses the existing IntegratedVendor create flow — clicking
 * a "Connect" button hands the chosen provider to
 * `integratedVendorActions.create`, which routes to the same form the
 * Settings → Integrated Vendors page already renders.
 */
export default class CarrierOnboardingPanelComponent extends Component {
    @service integratedVendorActions;
    @service router;

    @action
    connectProvider(providerCode) {
        // Prefer the existing IntegratedVendor create action if it exposes
        // a `create` modal/form (mirrors how the settings page wires up
        // new vendors). Fall back to a route transition with a query
        // param so the IntegratedVendor form can preselect the provider.
        if (
            this.integratedVendorActions &&
            typeof this.integratedVendorActions.create === 'function'
        ) {
            return this.integratedVendorActions.create({ provider: providerCode });
        }
        return this.router.transitionTo(
            'console.fleet-ops.management.integrated-vendors.new',
            { queryParams: { provider: providerCode } }
        );
    }
}
