import Service, { inject as service } from '@ember/service';
import isNotEmpty from '@fleetbase/ember-core/utils/is-not-empty';

export default class OrderValidationService extends Service {
    @service orderCreation;

    get isValid() {
        const order = this.orderCreation?.context?.order;
        const cfManager = this.orderCreation?.context?.cfManager;
        return this.validate(order, cfManager);
    }

    get isNotValid() {
        return !this.isValid;
    }

    validate(order, cfManager = null) {
        if (!order) return false;

        cfManager = cfManager ?? order?.cfManager ?? this.orderCreation.getContext('cfManager');
        const hasOrderConfig = isNotEmpty(order.order_config_uuid);
        const hasOrderType = isNotEmpty(order.type);
        const hasWaypoints = order.payload.waypoints.length >= 2;
        const hasPickup = isNotEmpty(order.payload.pickup);
        const hasDropoff = isNotEmpty(order.payload.dropoff);
        const hasValidCustomFields = cfManager ? this.isCustomFieldsValid(cfManager) : true;

        if (hasWaypoints) {
            return hasOrderConfig && hasOrderType && hasValidCustomFields;
        }

        return hasPickup && hasDropoff && hasOrderConfig && hasOrderType && hasValidCustomFields;
    }

    validationFails(order, cfManager) {
        return !this.validate(order, cfManager);
    }

    validateCustomFields(cfManager) {
        if (!cfManager) return true;

        return cfManager.validateRequired();
    }

    isCustomFieldsValid(cfManager) {
        if (!cfManager) return true;

        const { isValid } = this.validateCustomFields(cfManager);
        return isValid;
    }
}
