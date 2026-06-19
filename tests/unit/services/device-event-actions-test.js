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

        service.transitionTo = (routeName, resource) => {
            assert.strictEqual(routeName, 'connectivity.events.details');
            assert.strictEqual(resource, event);
        };

        service.transition.view(event);
    });
});
