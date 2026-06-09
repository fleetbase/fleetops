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
                    matching_order: ['provider_vehicle_id', 'internal_number', 'structure_number', 'plate_number', 'vehicle_card_id', 'trip_number'],
                    auto_create_fuel_reports: true,
                },
            },
        });
    }

    transition = {
        view: (connection) => this.transitionTo('connectivity.fuel-providers.index.details', connection),
        edit: (connection) => this.transitionTo('connectivity.fuel-providers.index.edit', connection),
        create: (provider) => {
            if (provider) {
                return this.transitionTo('connectivity.fuel-providers.index.new', { queryParams: { setupProvider: provider } });
            }

            return this.transitionTo('connectivity.fuel-providers.index.new');
        },
    };
}
