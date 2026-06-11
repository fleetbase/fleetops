import ResourceActionService from '@fleetbase/ember-core/services/resource-action';

export default class FuelIntegrationActionsService extends ResourceActionService {
    constructor() {
        super(...arguments);
        this.initialize('fuel-provider-connection', {
            defaultAttributes: {
                status: 'draft',
                environment: 'production',
                credentials: {},
                sync_settings: {
                    window_days: 7,
                    matching_order: ['plate_number', 'internal_id', 'vin', 'serial_number', 'call_sign', 'fuel_card_number', 'trip_number'],
                    auto_create_fuel_reports: true,
                },
            },
        });
    }

    transition = {
        view: (connection) => this.transitionTo('connectivity.fuel-providers.details', connection),
        edit: (connection) => this.transitionTo('connectivity.fuel-providers.edit', connection),
        create: (provider) => {
            if (provider) {
                return this.transitionTo('connectivity.fuel-providers.new', { queryParams: { setupProvider: provider } });
            }

            return this.transitionTo('connectivity.fuel-providers.new');
        },
    };
}
