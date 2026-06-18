import { module, test } from 'qunit';
import Service from '@ember/service';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import OrderFormDetailsComponent from '@fleetbase/fleetops-engine/components/order/form/details';

module('Integration | Component | order/form/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        class OrderConfigActionsStub extends Service {
            allOrderConfigs = [];
            loadAll = {
                perform() {},
            };
        }

        this.owner.register('service:order-config-actions', OrderConfigActionsStub);
    });

    test('it marks required create-order detail fields', async function (assert) {
        this.set('resource', {
            facilitator: {
                isIntegratedVendor: false,
            },
            order_config: null,
            payload: {},
            pod_required: true,
            required_skills: [],
        });

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        const requiredLabels = [...this.element.querySelectorAll('label.required')].map((label) => label.textContent.trim());

        assert.dom('label.required').exists({ count: 2 });
        assert.true(requiredLabels.includes('Order Type'));
        assert.true(requiredLabels.includes('Proof of Delivery'));
    });

    test('it does not render orchestrator constraint inputs', async function (assert) {
        this.set('resource', {
            facilitator: {
                isIntegratedVendor: false,
            },
            order_config: null,
            payload: {},
            pod_required: false,
            required_skills: [],
        });

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        assert.dom().doesNotContainText('Orchestrator Constraints');
        assert.dom().doesNotContainText('Time Window Start');
        assert.dom().doesNotContainText('Required Skills');
        assert.dom().doesNotContainText('Orchestrator Priority');
    });

    test('quote-relevant detail changes request service quote refresh', function (assert) {
        const requests = [];
        const resource = {
            payload: {
                set() {},
            },
            set(field, value) {
                this[field] = value;
            },
        };

        class OrderCreationStub extends Service {
            requestServiceQuoteRefresh(reason, order) {
                requests.push({ reason, order });
            }
        }

        this.owner.register('service:order-creation', OrderCreationStub);

        const component = new OrderFormDetailsComponent(this.owner, { resource });

        component.selectFacilitator({ id: 'facilitator-1' });
        component.setScheduledAt('2026-06-17T12:00:00Z');
        component.selectIntegratedServiceType('express');

        assert.deepEqual(
            requests.map((request) => request.reason),
            ['details.facilitator.changed', 'details.scheduled_at.changed', 'details.integrated_service_type.changed']
        );
        assert.true(
            requests.every((request) => request.order === resource),
            'requests refresh for the current order'
        );
    });
});
