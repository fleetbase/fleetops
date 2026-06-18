import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

const PAGE_LIMIT = 100;
const MAX_PAGES = 100;

export default class ConnectivityTelematicsDetailsAttachmentsRoute extends Route {
    @service store;

    queryParams = {
        query: { refreshModel: false },
        status: { refreshModel: false },
        vehicle: { refreshModel: false },
        sort: { refreshModel: true },
    };

    async model(params) {
        const telematic = this.modelFor('connectivity.telematics.details');
        const selectedVehicle = await this.loadSelectedVehicle(params.vehicle);
        const devices = [];
        let page = 1;
        let total = null;
        let lastPage = null;

        try {
            do {
                const pageDevices = await this.store.query('device', {
                    telematic_uuid: telematic.id,
                    sort: params.sort,
                    page,
                    limit: PAGE_LIMIT,
                });
                const records = Array.from(pageDevices ?? []);
                const meta = pageDevices?.meta ?? {};
                const perPage = Number(meta.per_page ?? meta.limit ?? PAGE_LIMIT);
                const currentPage = Number(meta.current_page ?? meta.currentPage ?? page);

                total = Number(meta.total ?? total ?? records.length);
                lastPage = Number(meta.last_page ?? meta.lastPage ?? (total && perPage ? Math.ceil(total / perPage) : null));

                devices.push(...records);

                if (records.length === 0 || (lastPage && currentPage >= lastPage) || (!lastPage && records.length < PAGE_LIMIT)) {
                    break;
                }

                page += 1;
            } while (page <= MAX_PAGES);

            return {
                devices,
                error: null,
                selectedVehicle,
                meta: {
                    total: total ?? devices.length,
                    loaded: devices.length,
                    pages: page,
                },
            };
        } catch (error) {
            return {
                devices: [],
                error,
                selectedVehicle,
                meta: {
                    total: 0,
                    loaded: 0,
                    pages: page,
                },
            };
        }
    }

    async loadSelectedVehicle(vehicleId) {
        if (!vehicleId) {
            return null;
        }

        try {
            return await this.store.findRecord('vehicle', vehicleId);
        } catch {
            return null;
        }
    }

    setupController(controller, model) {
        super.setupController(controller, model);
        controller.telematic = this.modelFor('connectivity.telematics.details');
        controller.selectedVehicle = model?.selectedVehicle ?? null;
    }
}
