import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { AbilitiesStub } from 'dummy/tests/helpers/stub-form-inputs';

function field(name) {
    const label = findAll('.field-name').find((el) => el.textContent.trim() === name);
    return label ? label.nextElementSibling.textContent.replace(/\s+/g, ' ').trim() : null;
}

module('Integration | Component | vehicle/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        this.owner.register('service:abilities', AbilitiesStub);
        const edited = (this.edited = []);
        this.owner.register(
            'service:resource-metadata',
            class extends Service {
                edit(resource) {
                    edited.push(resource);
                }
            }
        );
    });

    test('it renders the vehicle and the metadata edit action', async function (assert) {
        this.set('resource', {
            id: 'vehicle_1',
            name: 'Truck 1',
            internal_id: 'INT-1',
            plate_number: 'ABC-123',
            vin: 'VIN1',
            make: 'Volvo',
            model: 'FH16',
            year: 2020,
            status: 'available',
            driver_name: 'Sam Driver',
            call_sign: 'CS-1',
            location: { type: 'Point', coordinates: [-80.84, 35.22] },
            measurement_system: 'metric',
            odometer_unit: 'km',
            odometer: 120000,
            body_type: 'truck',
            meta: { fleet: 'north' },
            skills: ['refrigerated', 'hazmat'],
            max_tasks: 20,
        });

        await render(hbs`<Vehicle::Details @resource={{this.resource}} />`);

        assert.strictEqual(field('Name'), 'Truck 1');
        assert.strictEqual(field('Plate Number'), 'ABC-123');
        assert.strictEqual(field('Driver Assigned'), 'Sam Driver');
        assert.strictEqual(field('Status'), 'Available');
        assert.strictEqual(field('Odometer Unit'), null, 'the measurement panel starts collapsed');
        assert.strictEqual(field('Trim'), '-');
        assert.dom().includesText('refrigerated, hazmat');
        assert.dom().includesText('Max Tasks');
        assert.dom().includesText('fleet');
        assert.dom('[data-test-custom-fields]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:vehicle:details"]').exists();

        await click(findAll('button').find((button) => /Edit/.test(button.textContent)));
        assert.deepEqual(this.edited, [this.resource]);
    });
});
