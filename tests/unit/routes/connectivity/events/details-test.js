import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class AbilitiesServiceStub extends Service {
    denied = false;

    cannot() {
        return this.denied;
    }
}

class HostRouterServiceStub extends Service {
    transitions = [];

    transitionTo(routeName) {
        this.transitions.push(routeName);
        return routeName;
    }
}

class IntlServiceStub extends Service {
    t(key) {
        return key;
    }
}

class NotificationsServiceStub extends Service {
    warnings = [];
    serverErrors = [];

    warning(message) {
        this.warnings.push(message);
    }

    serverError(error) {
        this.serverErrors.push(error);
    }
}

class StoreServiceStub extends Service {
    findRecordCalls = [];

    findRecord(modelName, id) {
        this.findRecordCalls.push({ modelName, id });
        return { id, type: modelName };
    }
}

module('Unit | Route | connectivity/events/details', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:abilities', AbilitiesServiceStub);
        this.owner.register('service:host-router', HostRouterServiceStub);
        this.owner.register('service:intl', IntlServiceStub);
        this.owner.register('service:notifications', NotificationsServiceStub);
        this.owner.register('service:store', StoreServiceStub);
    });

    test('it exists', function (assert) {
        let route = this.owner.lookup('route:connectivity/events/details');
        assert.ok(route);
    });

    test('it fetches the selected device event by public id', function (assert) {
        let route = this.owner.lookup('route:connectivity/events/details');
        let store = this.owner.lookup('service:store');

        let model = route.model({ public_id: 'device_event_123' });

        assert.deepEqual(store.findRecordCalls, [{ modelName: 'device-event', id: 'device_event_123' }]);
        assert.deepEqual(model, { id: 'device_event_123', type: 'device-event' });
    });

    test('it redirects unauthorized users back to the events index', function (assert) {
        let route = this.owner.lookup('route:connectivity/events/details');
        let abilities = this.owner.lookup('service:abilities');
        let hostRouter = this.owner.lookup('service:host-router');
        let notifications = this.owner.lookup('service:notifications');

        abilities.denied = true;

        route.beforeModel();

        assert.deepEqual(notifications.warnings, ['common.unauthorized-access']);
        assert.deepEqual(hostRouter.transitions, ['console.fleet-ops.connectivity.events.index']);
    });

    test('it reports route errors and returns to the events index', function (assert) {
        let route = this.owner.lookup('route:connectivity/events/details');
        let hostRouter = this.owner.lookup('service:host-router');
        let notifications = this.owner.lookup('service:notifications');
        let error = new Error('device event request failed');

        route.error(error);

        assert.deepEqual(notifications.serverErrors, [error]);
        assert.deepEqual(hostRouter.transitions, ['console.fleet-ops.connectivity.events.index']);
    });
});
