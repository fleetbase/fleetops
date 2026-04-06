import Route from '@ember/routing/route';
import { inject as service } from '@ember/service';

/**
 * OperationsSchedulerIndexRoute
 *
 * Responsibilities:
 *   1. Access control — redirect if the user cannot list orders.
 *   2. Initial data loading — fetch active orders and drivers in parallel.
 *   3. Socket subscription lifecycle — delegate to the controller.
 *
 * All post-load logic (filtering, event mapping, conflict detection) lives
 * in the controller and the SchedulingService.
 */
export default class OperationsSchedulerIndexRoute extends Route {
    @service store;
    @service abilities;
    @service hostRouter;

    beforeModel() {
        if (this.abilities.cannot('fleet-ops list order')) {
            return this.hostRouter.transitionTo('console.fleet-ops.index');
        }
    }

    async model() {
        // Load active orders (created, dispatched, active) alongside drivers.
        // The broader status scope ensures dispatchers see each driver's full
        // day — not just pre-dispatch orders — enabling accurate conflict
        // detection and capacity visualisation.
        const [orders, drivers] = await Promise.all([
            this.store.query('order', {
                status: ['created', 'dispatched', 'active'],
                with: ['payload', 'driverAssigned.vehicle'],
                limit: 500,
            }),
            this.store.query('driver', {
                with: ['currentShift'],
                limit: 200,
            }),
        ]);

        return { orders, drivers };
    }

    setupController(controller, model) {
        super.setupController(controller, model);

        // Set drivers on the controller so the resource timeline can render them.
        // Orders are read reactively from the Ember Data store via computed getters,
        // so we do not need to set them explicitly here.
        controller.set('drivers', model.drivers.toArray());

        // Initialise viewDate to today in the company timezone so the calendar
        // opens on the correct day regardless of the server/browser timezone.
        // todayInCompanyTimezone is a getter on the controller that uses
        // _toCalendarDate(new Date()) to shift the current UTC instant into the
        // company-local date space that @event-calendar/core expects.
        controller.set('viewDate', controller.todayInCompanyTimezone);

        // Open SocketCluster subscriptions for real-time calendar updates.
        controller.subscribeToRealTimeUpdates();
    }

    resetController(controller) {
        // Close all socket channels when the dispatcher navigates away
        // to prevent memory leaks and stale event handlers.
        controller.unsubscribeFromRealTimeUpdates();
    }
}
