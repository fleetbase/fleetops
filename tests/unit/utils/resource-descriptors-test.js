import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import { registerResourceDescriptors, resolveResourceKey, getResourceDescriptor, getResourceDescriptors, readDescriptor } from '@fleetbase/ember-ui/utils/resource-registry';
import { buildFleetOpsResourceDescriptors } from '@fleetbase/fleetops-engine/utils/resource-descriptors';

/** Every model file in fleetops-data/addon/models. */
const MODEL_NAMES = [
    'asset-connection',
    'asset',
    'attachable-asset',
    'attachable-driver',
    'attachable-trailer',
    'attachable-vehicle',
    'attachable',
    'contact',
    'customer-contact',
    'customer-vendor',
    'customer',
    'device-event',
    'device',
    'driver',
    'entity',
    'equipment',
    'facilitator-contact',
    'facilitator-customer',
    'facilitator-driver',
    'facilitator-integrated-vendor',
    'facilitator-vendor',
    'facilitator',
    'fleet-driver',
    'fleet',
    'fuel-provider-connection',
    'fuel-provider-transaction',
    'fuel-report',
    'inspection-form',
    'inspection-item-result',
    'inspection-submission',
    'integrated-vendor',
    'issue',
    'maintenance-schedule',
    'maintenance-subject-equipment',
    'maintenance-subject-trailer',
    'maintenance-subject-vehicle',
    'maintenance-subject',
    'maintenance',
    'manifest-stop',
    'manifest',
    'order-config',
    'order',
    'part',
    'payload',
    'place',
    'position',
    'purchase-rate',
    'recurring-order-schedule',
    'route',
    'sensor',
    'service-area',
    'service-quote-item',
    'service-quote',
    'service-rate-fee',
    'service-rate-parcel-fee',
    'service-rate',
    'telematic',
    'tracking-number',
    'tracking-status',
    'trailer',
    'vehicle-device',
    'vehicle',
    'vendor',
    'warranty',
    'waypoint',
    'work-order',
    'zone',
];

const ALIASES = {
    'attachable-vehicle': 'vehicle',
    'maintenance-subject-vehicle': 'vehicle',
    'attachable-trailer': 'trailer',
    'maintenance-subject-trailer': 'trailer',
    'attachable-driver': 'driver',
    'facilitator-driver': 'driver',
    'maintenance-subject-equipment': 'equipment',
    'facilitator-vendor': 'vendor',
    'customer-vendor': 'vendor',
    'facilitator-integrated-vendor': 'integrated-vendor',
    'facilitator-contact': 'contact',
    'facilitator-customer': 'customer',
    'customer-contact': 'contact',
    'attachable-asset': 'asset',
    'fleet-driver': 'driver',
};

const FIRST_CLASS = 34;
const SUB_RECORDS = 16;
const BASES = 3;

module('Unit | Utility | resource-descriptors', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.descriptors = buildFleetOpsResourceDescriptors(this.owner);
        registerResourceDescriptors(this.owner, this.descriptors);
    });

    test('there is one descriptor per first-class resource, sub-record and polymorphic base', function (assert) {
        assert.strictEqual(MODEL_NAMES.length, 67, 'the fleetops-data model count');
        assert.strictEqual(this.descriptors.length, FIRST_CLASS + SUB_RECORDS + BASES);
        assert.strictEqual(getResourceDescriptors(this.owner).length, FIRST_CLASS + SUB_RECORDS + BASES);
        assert.deepEqual(
            this.descriptors.filter((d) => !d.key).map((d) => d),
            [],
            'every descriptor has a key'
        );
    });

    test('all 67 model names resolve, subtypes to their concrete resource', function (assert) {
        for (const modelName of MODEL_NAMES) {
            const key = resolveResourceKey(this.owner, modelName);
            assert.ok(key, `${modelName} resolves`);

            if (ALIASES[modelName]) {
                assert.strictEqual(key, ALIASES[modelName], `${modelName} is an alias of ${ALIASES[modelName]}`);
            } else {
                assert.strictEqual(key, modelName, `${modelName} is its own resource`);
            }
        }
    });

    test('fleet-ops:* prefixes and PHP class names resolve', function (assert) {
        assert.strictEqual(resolveResourceKey(this.owner, 'fleet-ops:vehicle'), 'vehicle');
        assert.strictEqual(resolveResourceKey(this.owner, 'fleet-ops:maintenance-schedule'), 'maintenance-schedule');
        assert.strictEqual(resolveResourceKey(this.owner, 'Fleetbase\\FleetOps\\Models\\Vehicle'), 'vehicle');
        assert.strictEqual(resolveResourceKey(this.owner, 'Fleetbase\\FleetOps\\Models\\IntegratedVendor'), 'integrated-vendor');
        assert.strictEqual(resolveResourceKey(this.owner, 'Fleetbase\\FleetOps\\Models\\WorkOrder'), 'work-order');
        assert.strictEqual(resolveResourceKey(this.owner, { resourceType: 'driver', name: 'stub' }), 'driver');
        assert.strictEqual(resolveResourceKey(this.owner, 'not-a-thing'), null);
    });

    test('every descriptor field tolerates an empty record', function (assert) {
        const fields = ['title', 'identifier', 'image', 'online', 'status', 'badges', 'selectDetails', 'facts'];

        for (const descriptor of getResourceDescriptors(this.owner)) {
            for (const field of fields) {
                if (typeof descriptor[field] !== 'function') {
                    continue;
                }

                let value;

                try {
                    value = descriptor[field]({});
                } catch (error) {
                    assert.ok(false, `${descriptor.key}.${field}({}) threw: ${error.message}`);
                    continue;
                }

                if (field === 'badges' || field === 'facts' || field === 'selectDetails') {
                    assert.ok(Array.isArray(value), `${descriptor.key}.${field} returns a list`);
                }
            }

            assert.strictEqual(readDescriptor(descriptor, 'title', null), undefined, `${descriptor.key}.title(null) does not throw`);
        }
    });

    test('identifiers never surface a UUID', function (assert) {
        const uuid = '9d2c6c5e-1b2a-4c3d-8e4f-1234567890ab';

        for (const descriptor of getResourceDescriptors(this.owner)) {
            const record = { id: uuid, uuid, public_id: 'thing_1', name: 'Thing', plate_number: null, driver_uuid: uuid, vehicle_uuid: uuid };
            const identifier = readDescriptor(descriptor, 'identifier', record);

            assert.notStrictEqual(identifier, uuid, `${descriptor.key}.identifier does not return the uuid`);
        }
    });

    test('the identity cell of every resource is its cell/<key>-identity wrapper', function (assert) {
        for (const descriptor of getResourceDescriptors(this.owner)) {
            assert.strictEqual(descriptor.components.identity, `cell/${descriptor.key}-identity`);
            assert.strictEqual(descriptor.components.pill, `${descriptor.key}/pill`);
            assert.strictEqual(descriptor.components.summary, `${descriptor.key}/summary`);
            assert.strictEqual(descriptor.components.selectOption, `select-option/${descriptor.key}`);
        }
    });

    test('vehicle and driver badges name each other and drop the row itself', function (assert) {
        const vehicle = getResourceDescriptor(this.owner, 'vehicle');
        const driver = getResourceDescriptor(this.owner, 'driver');

        assert.deepEqual(
            vehicle.badges({ plate_number: 'ABC-123', driver_name: 'Ada' }).map((b) => [b.key, b.label]),
            [
                ['plate', 'ABC-123'],
                ['driver', 'Ada'],
            ]
        );
        assert.deepEqual(
            driver.badges({ vehicle_name: 'Truck 1' }).map((b) => [b.key, b.label]),
            [['vehicle', 'Truck 1']]
        );
        assert.deepEqual(driver.badges({}), []);
    });

    test('the polymorphic bases delegate to the concrete descriptor', function (assert) {
        const facilitator = getResourceDescriptor(this.owner, 'facilitator');
        const record = { facilitator_type: 'fleet-ops:driver', name: 'Ada Driver', phone: '+1' };

        assert.strictEqual(facilitator.title(record), 'Ada Driver');
        assert.strictEqual(facilitator.identifier(record), '+1', 'the driver identifier');
        assert.true(facilitator.canOpen(record));
        assert.false(facilitator.canOpen({ name: 'nobody' }), 'nothing to delegate to');
    });
});
