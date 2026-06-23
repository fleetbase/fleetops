import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class DeviceEventActionsServiceStub extends Service {
    response = {
        device_event: {
            id: 'event_1',
            event_type: 'engine_fault',
            severity: 'warning',
            processed_at: '2026-06-23T12:00:00.000Z',
        },
    };

    markProcessedCalls = [];
    deferred;

    markProcessed(deviceEvent) {
        this.markProcessedCalls.push(deviceEvent);

        if (this.deferred) {
            return this.deferred.promise;
        }

        return this.response;
    }
}

class HostRouterServiceStub extends Service {
    refreshCalls = 0;
    transitions = [];

    refresh() {
        this.refreshCalls++;
    }

    transitionTo(...args) {
        this.transitions.push(args);
    }
}

module('Unit | Controller | connectivity/events/details', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:device-event-actions', DeviceEventActionsServiceStub);
        this.owner.register('service:host-router', HostRouterServiceStub);
    });

    test('it exists', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/events/details');
        assert.ok(controller);
    });

    test('mark processed updates the current model timestamp without refreshing the route', async function (assert) {
        let controller = this.owner.lookup('controller:connectivity/events/details');
        let deviceEventActions = this.owner.lookup('service:device-event-actions');
        let hostRouter = this.owner.lookup('service:host-router');
        let event = {
            id: 'event_1',
            event_type: 'engine_fault',
            severity: 'warning',
            processed_at: null,
        };

        controller.model = event;

        assert.false(controller.isProcessed);

        await controller.markProcessed();

        assert.deepEqual(deviceEventActions.markProcessedCalls, [event]);
        assert.true(controller.isProcessed);
        assert.strictEqual(controller.processedLabel, 'Processed');
        assert.strictEqual(controller.event, event);
        assert.ok(event.processed_at);
        assert.strictEqual(hostRouter.refreshCalls, 0);
    });

    test('processed state does not carry across route models', function (assert) {
        let controller = this.owner.lookup('controller:connectivity/events/details');
        let processedEvent = {
            id: 'event_1',
            processed_at: new Date('2026-06-23T12:00:00.000Z'),
        };
        let unprocessedEvent = {
            id: 'event_2',
            processed_at: null,
        };

        controller.model = processedEvent;

        assert.true(controller.isProcessed);

        controller.model = unprocessedEvent;

        assert.false(controller.isProcessed);
        assert.strictEqual(controller.event, unprocessedEvent);
    });

    test('mark processed exposes loading state and ignores duplicate clicks while running', async function (assert) {
        let controller = this.owner.lookup('controller:connectivity/events/details');
        let deviceEventActions = this.owner.lookup('service:device-event-actions');
        let event = {
            id: 'event_1',
            event_type: 'engine_fault',
            severity: 'warning',
            processed_at: null,
        };
        let resolveDeferred;

        deviceEventActions.deferred = {
            promise: new Promise((resolve) => {
                resolveDeferred = resolve;
            }),
        };
        controller.model = event;

        let markProcessedPromise = controller.markProcessed();

        assert.true(controller.isMarkingProcessed);

        await controller.markProcessed();

        assert.deepEqual(deviceEventActions.markProcessedCalls, [event], 'duplicate calls are ignored while loading');

        resolveDeferred(deviceEventActions.response);
        await markProcessedPromise;

        assert.false(controller.isMarkingProcessed);
        assert.true(controller.isProcessed);
        assert.ok(event.processed_at);
    });
});
