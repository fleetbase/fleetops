import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';

module('Unit | Service | device-event-actions', function (hooks) {
    setupTest(hooks);

    // TODO: Replace this with your real tests.
    test('it exists', function (assert) {
        let service = this.owner.lookup('service:device-event-actions');
        assert.ok(service);
    });

    test('transition view targets the registered connectivity event details route', function (assert) {
        let service = this.owner.lookup('service:device-event-actions');
        let event = { id: 'event_1' };

        const hostRouter = this.owner.lookup('service:host-router');

        service.transition.view(event);

        assert.deepEqual(hostRouter.calls, [{ method: 'transitionTo', args: ['console.fleet-ops.connectivity.events.details', event] }], 'the route is prefixed with the engine mount point');
    });
});
