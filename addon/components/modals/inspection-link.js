import Component from '@glimmer/component';
import { inject as service } from '@ember/service';
import { action, get, set } from '@ember/object';
import copyToClipboard from '@fleetbase/ember-core/utils/copy-to-clipboard';
import { toDatetimeLocal } from '../../services/inspection-form-actions';

export default class ModalsInspectionLinkComponent extends Component {
    @service intl;
    @service notifications;

    get formState() {
        return this.args.options.formState;
    }

    /** A link cannot be made to expire in the past; the server refuses it too. */
    get minExpiry() {
        return toDatetimeLocal(new Date());
    }

    /**
     * Who the PIN would be sent to: whoever the link is assigned to, or else
     * the driver's own account. The server makes the same choice.
     *
     * Read with `get`: the form state is a plain object changed with `set`,
     * and a native read of it is not tracked, so a getter reading it directly
     * never recomputed and email and SMS stayed disabled after a pick.
     */
    get recipientName() {
        const assignee = get(this.formState, 'assignee');
        const driver = get(this.formState, 'driver');
        return assignee?.name ?? driver?.name ?? null;
    }

    get deliveryHelp() {
        const name = this.recipientName;
        return name ? this.intl.t('inspection.link.pin-delivery-help', { name }) : this.intl.t('inspection.link.pin-delivery-no-recipient');
    }

    /** With nobody left to send it to, the PIN goes back to being shared by hand. */
    keepDeliveryPossible() {
        if (!this.recipientName && get(this.formState, 'pin_delivery') !== 'none') {
            set(this.formState, 'pin_delivery', 'none');
        }
    }

    @action assignAssignee(user) {
        set(this.formState, 'assignee', user);
        this.keepDeliveryPossible();
    }

    @action assignDriver(driver) {
        set(this.formState, 'driver', driver);
        this.keepDeliveryPossible();
    }

    @action assignVehicle(vehicle) {
        set(this.formState, 'vehicle', vehicle);
    }

    @action updateExpiry(event) {
        set(this.formState, 'expires_at', event.target.value);
    }

    @action setPinDelivery(event) {
        set(this.formState, 'pin_delivery', event.target.value);
    }

    @action copyLink() {
        const url = this.formState.generated?.url;
        if (url) {
            copyToClipboard(url);
            this.notifications.success(this.intl.t('inspection.link.copied'));
        }
    }

    @action copyPin() {
        const pin = this.formState.generated?.pin;
        if (pin) {
            copyToClipboard(pin);
            this.notifications.success(this.intl.t('inspection.link.copied-pin'));
        }
    }
}
