import setupCustomerPortal from '../utils/setup-customer-portal';

export function initialize(engineInstance) {
    const universe = engineInstance.lookup('service:universe');
    setupCustomerPortal(engineInstance, universe);
}

export default {
    initialize,
};
