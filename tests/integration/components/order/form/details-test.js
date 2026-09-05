import { module, test } from 'qunit';
import Service from '@ember/service';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Component from '@glimmer/component';
import { action } from '@ember/object';
import { setComponentTemplate } from '@ember/component';
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

        this.registerOrderConfigs = (configs) => {
            this.owner.unregister('service:order-config-actions');
            this.owner.register(
                'service:order-config-actions',
                class extends Service {
                    allOrderConfigs = configs;
                    loadAll = {
                        perform() {},
                    };
                }
            );
        };
        // The order-type picker is a PowerSelect over the loaded configs; the stand-in yields the
        // same block and hands `@onChange` either one of the options or null.
        this.stubOrderConfigSelect = () => {
            registerTemplateOnly(
                this.owner,
                'power-select',
                hbs`{{#each @options as |option|}}<button type="button" data-test-order-config={{option.key}} {{on "click" (fn @onChange option)}}>{{yield option}}</button>{{/each}}<button type="button" data-test-order-config-clear {{on "click" (fn @onChange null)}}></button>`
            );
        };
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

    test('the integrated service-type select shows the type already on the order', async function (assert) {
        registerTemplateOnly(this.owner, 'select', hbs`<div data-test-service-type={{@value}}></div>`);

        this.set(
            'resource',
            makeRecord('order', {
                order_config: null,
                required_skills: [],
                type: 'same-day',
                facilitator: {
                    isIntegratedVendor: true,
                    name: 'Integrated Vendor',
                    service_types: [{ key: 'same-day', description: 'Same day' }],
                },
                payload: {},
            })
        );

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        assert.dom('[data-test-service-type]').hasAttribute('data-test-service-type', 'same-day');
    });

    test('the customer select records the identifier and the type the model carries', async function (assert) {
        // The customer select is one of four model selects in this form, so the stand-in keys its
        // buttons by `@modelName` to keep the facilitator and driver selects out of the way.
        registerTemplateOnly(
            this.owner,
            'model-select',
            hbs`<button type="button" data-test-pick-uuid={{@modelName}} {{on "click" (fn @onChange (hash uuid="customer_uuid_1" customer_type="contact"))}}></button>
                <button type="button" data-test-pick-id={{@modelName}} {{on "click" (fn @onChange (hash id="customer_id_1"))}}></button>
                <button type="button" data-test-pick-none={{@modelName}} {{on "click" (fn @onChange null)}}></button>`
        );

        this.set('resource', makeRecord('order', { order_config: null, required_skills: [], facilitator: null, payload: {} }));

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        await click('[data-test-pick-uuid="customer"]');
        assert.strictEqual(this.resource.customer_uuid, 'customer_uuid_1', 'a uuid identifies the customer when it has one');
        assert.strictEqual(this.resource.customer_type, 'fleet-ops:contact', 'the type is namespaced for the polymorphic relation');

        await click('[data-test-pick-id="customer"]');
        assert.strictEqual(this.resource.customer_uuid, 'customer_id_1', 'an id stands in when there is no uuid');
        assert.strictEqual(this.resource.customer_type, null, 'a model with no customer type clears the type');

        await click('[data-test-pick-none="customer"]');
        assert.strictEqual(this.resource.customer, null);
        assert.strictEqual(this.resource.customer_uuid, null, 'clearing the customer clears its identifier');
        assert.strictEqual(this.resource.customer_type, null);
    });

    test('choosing an order type loads its custom fields and announces them', async function (assert) {
        const contexts = [];
        const refreshes = [];
        const loaded = [];
        const manager = { fields: ['reference'] };

        class OrderCreationStub extends Service {
            addContext(key, value) {
                contexts.push([key, value]);
            }

            requestServiceQuoteRefresh(reason) {
                refreshes.push(reason);
            }
        }

        class CustomFieldsRegistryStub extends Service {
            loadSubjectCustomFields = {
                perform: async (subject) => {
                    loaded.push(subject);
                    return manager;
                },
            };
        }

        this.owner.register('service:order-creation', OrderCreationStub);
        this.owner.register('service:custom-fields-registry', CustomFieldsRegistryStub);
        this.registerOrderConfigs([{ id: 'config_1', key: 'courier', name: 'Courier', description: 'Same day courier' }]);
        this.stubOrderConfigSelect();

        const payloadWrites = [];
        const announced = [];
        this.set('onCustomFieldsReady', (customFields) => announced.push(customFields));
        this.set(
            'resource',
            makeRecord('order', {
                order_config: null,
                required_skills: [],
                facilitator: null,
                payload: {
                    set(key, value) {
                        payloadWrites.push([key, value]);
                    },
                },
            })
        );

        await render(hbs`<Order::Form::Details @resource={{this.resource}} @onCustomFieldsReady={{this.onCustomFieldsReady}} />`);
        await click('[data-test-order-config="courier"]');

        assert.strictEqual(this.resource.order_config_uuid, 'config_1');
        assert.strictEqual(this.resource.type, 'courier');
        assert.deepEqual(payloadWrites, [['type', 'courier']], 'the order type is mirrored onto the payload');
        assert.deepEqual(refreshes, ['details.order_config.changed']);
        assert.deepEqual(loaded, [this.resource.order_config], 'the chosen config is the custom-field subject');
        assert.deepEqual(contexts, [['cfManager', manager]], 'the manager is published to the order-creation context');
        assert.strictEqual(this.resource.cfManager, manager);
        assert.deepEqual(announced, [manager], 'the form announces the manager to its caller');
    });

    test('an order type that fails to load its custom fields leaves the rest of the form set', async function (assert) {
        const test = this;
        let failNext = false;

        class CustomFieldsRegistryStub extends Service {
            loadSubjectCustomFields = {
                perform: async () => {
                    if (failNext) {
                        throw new Error('custom fields unavailable');
                    }

                    return test.manager;
                },
            };
        }

        this.manager = { fields: [] };
        this.owner.register('service:custom-fields-registry', CustomFieldsRegistryStub);
        this.registerOrderConfigs([
            { id: 'config_1', key: 'courier', name: 'Courier' },
            { id: 'config_2', key: 'freight', name: 'Freight' },
        ]);
        this.stubOrderConfigSelect();

        this.set('resource', makeRecord('order', { order_config: null, required_skills: [], facilitator: null, payload: { set() {} } }));

        // No `@onCustomFieldsReady` here: the form still has to publish the manager onto itself.
        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        await click('[data-test-order-config="courier"]');
        assert.strictEqual(this.resource.cfManager, this.manager, 'the manager is stored even with no listener to announce it to');

        failNext = true;
        await click('[data-test-order-config="freight"]');
        assert.strictEqual(this.resource.type, 'freight', 'the order type is still applied');
        assert.strictEqual(this.resource.cfManager, this.manager, 'a failed load leaves the previous manager in place');

        this.resource.type = 'untouched';
        await click('[data-test-order-config-clear]');
        assert.strictEqual(this.resource.type, 'untouched', 'clearing the select is ignored rather than written through');
    });

    test('assigning a driver carries its vehicle and tracks the driver on the map', async function (assert) {
        const layerCalls = [];

        class LeafletLayerVisibilityManagerStub extends Service {
            hideCategory(category) {
                layerCalls.push(['hideCategory', category]);
            }

            showModelLayer(model) {
                layerCalls.push(['showModelLayer', model?.id ?? null]);
            }
        }

        this.owner.register('service:leaflet-layer-visibility-manager', LeafletLayerVisibilityManagerStub);

        const vehicle = { id: 'vehicle_1' };
        // `vehicle` is a getter so the rejected promise is only created once the task is already
        // awaiting it, rather than sitting unhandled from the moment the fixture is built.
        const test = this;
        this.drivers = {
            driving: {
                id: 'driver_1',
                get vehicle() {
                    return Promise.resolve(vehicle);
                },
            },
            walking: {
                id: 'driver_2',
                get vehicle() {
                    return Promise.resolve(null);
                },
            },
            broken: {
                id: 'driver_3',
                get vehicle() {
                    return Promise.reject(new Error('vehicle lookup failed'));
                },
            },
        };

        class DriverSelectStub extends Component {
            @action pick(which) {
                this.args.onChange(test.drivers[which]);
            }
        }

        this.owner.register(
            'component:model-select',
            setComponentTemplate(
                hbs`<button type="button" data-test-pick-driving={{@modelName}} {{on "click" (fn this.pick "driving")}}></button>
                    <button type="button" data-test-pick-walking={{@modelName}} {{on "click" (fn this.pick "walking")}}></button>
                    <button type="button" data-test-pick-broken={{@modelName}} {{on "click" (fn this.pick "broken")}}></button>`,
                DriverSelectStub
            )
        );

        this.set('resource', makeRecord('order', { order_config: null, required_skills: [], facilitator: null, payload: {} }));

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        await click('[data-test-pick-driving="driver"]');
        assert.strictEqual(this.resource.driver_assigned.id, 'driver_1');
        assert.strictEqual(this.resource.vehicle_assigned, vehicle, "the driver's vehicle is assigned with them");
        assert.deepEqual(layerCalls, [
            ['hideCategory', 'drivers'],
            ['showModelLayer', 'driver_1'],
        ]);

        await click('[data-test-pick-walking="driver"]');
        assert.strictEqual(this.resource.vehicle_assigned, vehicle, 'a driver with no vehicle leaves the previous one alone');
        assert.deepEqual(layerCalls.at(-1), ['showModelLayer', 'driver_2'], 'the new driver is still tracked');

        await click('[data-test-pick-broken="driver"]');
        assert.strictEqual(this.resource.driver_assigned.id, 'driver_3', 'a failed vehicle lookup still assigns the driver');
        assert.deepEqual(layerCalls.at(-1), ['showModelLayer', 'driver_3'], 'and still tracks them');
    });

    test('the ad-hoc and proof-of-delivery toggles carry their dependent fields', async function (assert) {
        class CurrentUserStub extends Service {
            getCompanyOption(key, defaultValue) {
                return key === 'fleetops.adhoc_distance' ? 12000 : defaultValue;
            }
        }

        this.owner.register('service:current-user', CurrentUserStub);
        // A fresh order carries neither flag yet; ember-ui's Toggle only defers to `@isToggled`
        // when it is a boolean, so leaving them unset lets each toggle own its own state.
        this.set('resource', makeRecord('order', { order_config: null, required_skills: [], facilitator: null, payload: {} }));

        await render(hbs`<Order::Form::Details @resource={{this.resource}} />`);

        const toggles = findAll('[role="checkbox"]');
        assert.strictEqual(toggles.length, 3, 'ad-hoc, dispatch and proof of delivery');

        await click(toggles[0]);
        assert.true(this.resource.adhoc);
        assert.strictEqual(this.resource.adhoc_distance, 12000, 'the ad-hoc radius comes from the company option');

        await click(toggles[2]);
        assert.true(this.resource.pod_required);
        assert.strictEqual(this.resource.pod_method, 'scan', 'requiring proof defaults the method to a scan');

        await click(toggles[2]);
        assert.false(this.resource.pod_required);
        assert.strictEqual(this.resource.pod_method, null, 'no longer requiring proof clears the method');
    });
});
