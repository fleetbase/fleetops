import { module, test } from 'qunit';
import Service from '@ember/service';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs, { AbilitiesStub, makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

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
        this.owner.register('service:abilities', AbilitiesStub);
        stubFormInputs(this.owner);
    });

    test('it marks required create-order detail fields', async function (assert) {
        this.set(
            'resource',
            makeRecord('order', {
                facilitator: {
                    isIntegratedVendor: false,
                },
                order_config: null,
                payload: {},
                pod_required: true,
                required_skills: [],
            })
        );

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        const requiredLabels = [...this.element.querySelectorAll('label.required')].map((label) => label.textContent.trim());

        assert.dom('label.required').exists({ count: 2 });
        assert.true(requiredLabels.includes('Order Type'));
        assert.true(requiredLabels.includes('Proof of Delivery'));
    });

    test('it does not render orchestrator constraint inputs', async function (assert) {
        this.set(
            'resource',
            makeRecord('order', {
                facilitator: {
                    isIntegratedVendor: false,
                },
                order_config: null,
                payload: {},
                pod_required: false,
                required_skills: [],
            })
        );

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        assert.dom().doesNotContainText('Orchestrator Constraints');
        assert.dom().doesNotContainText('Time Window Start');
        assert.dom().doesNotContainText('Required Skills');
        assert.dom().doesNotContainText('Orchestrator Priority');
    });

    test('quote-relevant detail changes request service quote refresh', async function (assert) {
        const requests = [];

        class OrderCreationStub extends Service {
            requestServiceQuoteRefresh(reason, order) {
                requests.push({ reason, order });
            }
        }

        this.owner.register('service:order-creation', OrderCreationStub);
        // The scheduled-at picker and the service-type select are the two controls that reach
        // these actions; stand them in so the test can drive them.
        registerTemplateOnly(this.owner, 'date-time-input', hbs`<button type="button" data-test-date-time-input {{on "click" (fn @onUpdate "2026-06-17T12:00:00Z")}}></button>`);
        registerTemplateOnly(this.owner, 'select', hbs`<button type="button" data-test-select {{on "click" (fn @onSelect "express")}}></button>`);

        const payloadWrites = [];
        this.set(
            'resource',
            makeRecord('order', {
                order_config: null,
                required_skills: [],
                facilitator: {
                    isIntegratedVendor: true,
                    name: 'Integrated Vendor',
                    service_types: [{ key: 'express', description: 'Express' }],
                },
                payload: {
                    set(key, value) {
                        payloadWrites.push([key, value]);
                    },
                },
            })
        );

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        await click('[data-test-model-select="facilitator"]');
        await click('[data-test-date-time-input]');
        await click('[data-test-select]');

        assert.deepEqual(
            requests.map((request) => request.reason),
            ['details.facilitator.changed', 'details.scheduled_at.changed', 'details.integrated_service_type.changed']
        );
        assert.true(
            requests.every((request) => request.order === this.resource),
            'requests refresh for the current order'
        );
        assert.strictEqual(this.resource.facilitator.id, 'picked_1', 'the picked facilitator is assigned');
        assert.strictEqual(this.resource.driver, null, 'changing the facilitator clears the driver');
        assert.strictEqual(this.resource.scheduled_at, '2026-06-17T12:00:00Z');
        assert.strictEqual(this.resource.type, 'express');
        assert.deepEqual(payloadWrites, [['type', 'express']], 'the service type is mirrored onto the payload');
    });
});
