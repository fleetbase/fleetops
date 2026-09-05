import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

const TEXT_INPUTS = 'input:not([data-test-date-picker]):not([data-test-money-input]):not([data-test-unit-input]):not([type="checkbox"])';

// Every plain text/number/time input the template binds, in DOM order.
const FIELDS = [
    ['name', 'Truck 1'],
    ['internal_id', 'INT-1'],
    ['plate_number', 'ABC-123'],
    ['vin', 'VIN123'],
    ['make', 'Volvo'],
    ['model', 'FH16'],
    ['year', '2020'],
    ['trim', 'Globetrotter'],
    ['color', 'Blue'],
    ['serial_number', 'SN-1'],
    ['fuel_card_number', 'FC-1'],
    ['class', 'Heavy'],
    ['call_sign', 'CS-1'],
    ['odometer', '120000'],
    ['odometer_at_purchase', '1000'],
    ['engine_number', 'EN-1'],
    ['engine_make', 'Volvo'],
    ['engine_model', 'D16'],
    ['engine_family', 'D'],
    ['engine_configuration', 'Inline'],
    ['cylinder_arrangement', 'I6'],
    ['number_of_cylinders', '6'],
    ['horsepower_rpm', '1800'],
    ['torque_rpm', '1200'],
    ['seating_capacity', '2'],
    ['payload_capacity_volume', '90'],
    ['payload_capacity_pallets', '33'],
    ['payload_capacity_parcels', '500'],
    ['emission_standard', 'Euro 6'],
    ['depreciation_rate', '12.5'],
    ['estimated_service_life_distance', '1000000'],
    ['estimated_service_life_months', '120'],
    ['loan_number_of_payments', '60'],
    ['time_window_start', '08:00'],
    ['time_window_end', '18:00'],
    ['max_tasks', '20'],
];

module('Integration | Component | vehicle/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        registerTemplateOnly(
            this.owner,
            'upload-button',
            hbs`<button type="button" data-test-upload-button disabled={{@disabled}} {{on "click" (fn @onFileAdded (hash name="photo.png"))}}>{{@buttonText}}</button>`
        );
        registerTemplateOnly(this.owner, 'model-coordinates-input', hbs`<div data-test-coordinates disabled={{@disabled}}></div>`);
        registerTemplateOnly(this.owner, 'metadata-editor', hbs`<div data-test-metadata></div>`);
        registerTemplateOnly(this.owner, 'currency-select', hbs`<div data-test-currency={{@currency}}></div>`);
        registerTemplateOnly(this.owner, 'multi-select', hbs`<div data-test-multi-select disabled={{@disabled}}></div>`);
        const calls = (this.calls = []);
        const test = this;
        this.uploadFails = false;
        this.allowWrite = true;
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return test.allowWrite;
                }

                cannot() {
                    return !test.allowWrite;
                }
            }
        );
        this.owner.register(
            'service:current-user',
            class extends Service {
                companyId = 'company_1';
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                error(message) {
                    calls.push(['error', message]);
                }
            }
        );
        this.owner.register(
            'service:fetch',
            class extends Service {
                uploadFile = {
                    perform: async (file, options, callback) => {
                        calls.push(['upload', file, options]);
                        if (test.uploadFails) {
                            throw new Error('disk full');
                        }
                        callback({ id: 'file_1', url: '/photo.png' });
                    },
                };
            }
        );
    });

    test('it renders every section bound to the vehicle and applies the edits', async function (assert) {
        this.set('resource', makeRecord('vehicle', { id: 'vehicle_1', status: 'available', currency: 'USD', dpf_equipped: false, ...Object.fromEntries(FIELDS) }, { isNew: false }));

        await render(hbs`<Vehicle::Form @resource={{this.resource}} />`);

        assert.deepEqual(
            findAll(TEXT_INPUTS).map((input) => input.value),
            FIELDS.map(([, value]) => value)
        );
        assert.dom('.ember-power-select-trigger').exists({ count: 11 });
        assert.dom('[data-test-unit-input]').exists({ count: 18 });
        assert.dom('[data-test-money-input]').exists({ count: 4 });
        assert.dom('[data-test-date-picker]').exists({ count: 3 });
        assert.dom('.fleetbase-checkbox').exists({ count: 2 });
        assert.dom('[data-test-model-select="driver"]').exists();
        assert.dom('[data-test-coordinates]').exists();
        assert.dom('[data-test-currency]').hasAttribute('data-test-currency', 'USD');
        assert.dom('[data-test-multi-select]').exists();
        assert.dom('[data-test-avatar-picker]').exists();
        assert.dom('[data-test-metadata]').exists();
        assert.dom('[data-test-custom-fields]').exists();
        assert.deepEqual(
            findAll('[data-test-registry]').map((element) => element.getAttribute('data-test-registry')),
            [
                'fleet-ops:component:vehicle:form:start',
                'fleet-ops:component:vehicle:form:details',
                'fleet-ops:component:vehicle:form:after-details',
                'fleet-ops:component:vehicle:form',
                'fleet-ops:component:vehicle:form:end',
            ]
        );
        for (const title of ['Measurement & Units', 'Body & Usage', 'Technical Specifications', 'Financial & Lifecycle', 'Orchestrator Constraints']) {
            assert.dom().includesText(title);
        }
        assert.dom().includesText('Available');
        assert.dom(`${TEXT_INPUTS}[disabled]`).doesNotExist();

        await click('[data-test-model-select="driver"]');
        assert.strictEqual(this.resource.driver.id, 'picked_1');
        assert.deepEqual(this.resource.meta, {}, 'assigning a driver seeds the meta so the change is tracked');
        this.resource.meta = { note: 'kept' };
        await click('[data-test-model-select="driver"]');
        assert.deepEqual(this.resource.meta, { note: 'kept' });

        await click(findAll('.ember-power-select-trigger')[0]);
        await click(findAll('.ember-power-select-option')[1]);
        assert.strictEqual(this.resource.status, 'in_use');

        await click(findAll('.fleetbase-checkbox')[0]);
        assert.true(this.resource.dpf_equipped);

        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls[0][2], { path: 'uploads/company_1/vehicles/vehicle_1', subject_uuid: 'vehicle_1', subject_type: 'fleet-ops:vehicle', type: 'vehicle_photo' });
        assert.strictEqual(this.resource.photo_uuid, 'file_1');
        assert.strictEqual(this.resource.photo_url, '/photo.png');

        this.uploadFails = true;
        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls.at(-1), ['error', 'Unable to upload photo: disk full']);
    });

    test('without write access the inputs, selects and pickers are disabled', async function (assert) {
        this.allowWrite = false;
        this.set('resource', makeRecord('vehicle', { id: 'vehicle_2', name: 'Locked' }, { isNew: false }));

        await render(hbs`<Vehicle::Form @resource={{this.resource}} />`);

        assert.strictEqual(findAll(`${TEXT_INPUTS}[disabled]`).length, FIELDS.length, 'every text input honours cannot-write');
        assert.dom('.ember-power-select-trigger[aria-disabled="true"]').exists({ count: 11 });
        assert.dom('[data-test-upload-button]').isDisabled();
        assert.dom('[data-test-coordinates]').hasAttribute('disabled');
        assert.dom('[data-test-multi-select]').hasAttribute('disabled');
        assert.dom('[data-test-avatar-picker]').hasAttribute('disabled');
    });
});
