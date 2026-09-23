import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class AbilitiesStubService extends Service {
    allowed = true;

    cannot() {
        return !this.allowed;
    }
}

class HostRouterStubService extends Service {
    transitions = [];

    transitionTo(route) {
        this.transitions.push(route);
        return route;
    }
}

class NotificationsStubService extends Service {
    warnings = [];

    warning(message) {
        this.warnings.push(message);
    }
}

module('Unit | Route | settings/telematics', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:abilities', AbilitiesStubService);
        this.owner.register('service:host-router', HostRouterStubService);
        this.owner.register('service:notifications', NotificationsStubService);
    });

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:settings/telematics');
        assert.ok(route);
    });

    test('it lets permitted users through and redirects everyone else', function (assert) {
        const route = this.owner.lookup('route:settings/telematics');
        const abilities = this.owner.lookup('service:abilities');
        const hostRouter = this.owner.lookup('service:host-router');
        const notifications = this.owner.lookup('service:notifications');

        assert.strictEqual(route.beforeModel(), undefined);
        assert.deepEqual(hostRouter.transitions, []);

        abilities.allowed = false;
        assert.strictEqual(route.beforeModel(), 'console.fleet-ops');
        assert.deepEqual(hostRouter.transitions, ['console.fleet-ops']);
        assert.strictEqual(notifications.warnings.length, 1);
    });
});
