import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * Orders are the FleetOps landing route (`console.fleet-ops` resolves here), so a user
 * without `fleet-ops list order` cannot be sent back to `console.fleet-ops` - that would
 * loop. Instead they are forwarded to the first FleetOps area they are permitted to list,
 * falling back to the console when there is none.
 */
const FALLBACK_ROUTES = [
    ['fleet-ops list driver', 'console.fleet-ops.management.drivers'],
    ['fleet-ops list vehicle', 'console.fleet-ops.management.vehicles'],
    ['fleet-ops list trailer', 'console.fleet-ops.management.trailers'],
    ['fleet-ops list fleet', 'console.fleet-ops.management.fleets'],
    ['fleet-ops list place', 'console.fleet-ops.management.places'],
    ['fleet-ops list contact', 'console.fleet-ops.management.contacts'],
    ['fleet-ops list vendor', 'console.fleet-ops.management.vendors'],
    ['fleet-ops list fuel-report', 'console.fleet-ops.management.fuel-reports'],
    ['fleet-ops list issue', 'console.fleet-ops.management.issues'],
    ['fleet-ops list work-order', 'console.fleet-ops.maintenance.work-orders'],
    ['fleet-ops list device', 'console.fleet-ops.connectivity.devices'],
    ['iam list report', 'console.fleet-ops.analytics.reports'],
];

export default class OperationsOrdersRoute extends Route {
    @service notifications;
    @service hostRouter;
    @service abilities;
    @service intl;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list order')) {
            const fallback = FALLBACK_ROUTES.find(([permission]) => this.abilities.can(permission));
            if (fallback) {
                return this.hostRouter.transitionTo(fallback[1]);
            }

            this.notifications.warning(this.intl.t('common.unauthorized-access'));
            return this.hostRouter.transitionTo('console');
        }
    }
}
