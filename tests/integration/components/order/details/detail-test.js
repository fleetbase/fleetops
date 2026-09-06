import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import { AbilitiesStub } from 'dummy/tests/helpers/stub-form-inputs';

function buttonByText(pattern) {
    return findAll('button').find((button) => pattern.test(button.textContent));
}

module('Integration | Component | order/details/detail', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register('service:abilities', AbilitiesStub);
        this.owner.register(
            'service:order-actions',
            class extends Service {
                editOrderDetails(order) {
                    calls.push(['editOrderDetails', order.public_id]);
                }

                assignDriver(order) {
                    calls.push(['assignDriver', order.public_id]);
                }
            }
        );
        this.owner.register(
            'service:driver-actions',
            class extends Service {
                panel = { view: (driver) => calls.push(['panel.view', driver.name]) };
            }
        );
        this.owner.register(
            'service:map-manager',
            class extends Service {
                focusResource(resource, zoom) {
                    calls.push(['focusResource', resource.name, zoom]);
                }
            }
        );
    });

    test('it renders the order details and the driver actions', async function (assert) {
        this.set('resource', {
            public_id: 'order_1',
            internal_id: 'INT-1',
            tracking_number: { tracking_number: 'FLE1' },
            status: 'created',
            dispatched: true,
            adhoc: true,
            driver_assigned_uuid: 'driver_1',
            driver_assigned: { id: 'driver_1', name: 'Sam Driver' },
            vehicle_assigned: { id: 'vehicle_1', name: 'Truck 1', display_name: 'Truck 1', displayName: 'Truck 1' },
            customer: { name: 'Acme', phone: '+1555' },
            facilitator: null,
            scheduledAt: '12 May 2026',
            type: 'transport',
            pod_required: true,
            pod_method: 'signature',
            time_window_start: '08:00',
            required_skills: ['hazmat'],
            orchestrator_priority: 5,
        });

        await render(hbs`<Order::Details::Detail @resource={{this.resource}} />`);

        assert.dom().includesText('Dispatched');
        assert.dom().includesText('Ad-Hoc');
        assert.dom().includesText('Sam Driver');
        assert.dom().includesText('Truck 1');
        assert.dom().includesText('Acme');
        assert.dom().includesText('No facilitator');
        assert.dom().includesText('FLE1');
        assert.dom().includesText('Transport');
        assert.dom().includesText('Signature');
        assert.dom().includesText('Orchestrator Constraints');
        assert.dom().includesText('hazmat');
        assert.ok(buttonByText(/Change Driver/), 'an assigned driver offers a change');
        assert.notOk(buttonByText(/Change Driver/).disabled);

        await click(buttonByText(/^\s*Edit\s*$/));
        await click(buttonByText(/Change Driver/));
        await click(findAll('a, button').find((el) => /Sam Driver/.test(el.textContent)));
        assert.deepEqual(this.calls, [
            ['editOrderDetails', 'order_1'],
            ['assignDriver', 'order_1'],
            ['panel.view', 'Sam Driver'],
            ['focusResource', 'Sam Driver', 18],
        ]);
    });

    test('a canceled order without a driver disables the actions and offers an assignment', async function (assert) {
        this.set('resource', { public_id: 'order_2', status: 'canceled', driver_assigned: null, customer: null, isMultiDrop: true, orderWaypoints: [] });

        await render(hbs`<Order::Details::Detail @resource={{this.resource}} />`);

        assert.ok(buttonByText(/Assign Driver/).disabled);
        assert.ok(buttonByText(/^\s*Edit\s*$/).disabled);
        assert.dom().includesText('No driver assigned');
        assert.dom().includesText('Customers');
        assert.dom().doesNotIncludeText('Orchestrator Constraints');
    });
});
