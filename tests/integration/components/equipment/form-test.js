import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { click, find, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { selectFiles } from 'ember-file-upload/test-support';
import stubFormInputs, { AbilitiesStub, makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

function group(label) {
    return findAll('.input-group').find((element) => element.querySelector('label')?.textContent.trim() === label);
}

async function chooseAssetType(label) {
    await click(group('Asset Type').querySelector('.ember-power-select-trigger'));
    await click([...document.querySelectorAll('.ember-power-select-option')].find((option) => option.textContent.trim() === label));
}

module('Integration | Component | equipment/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        stubFormInputs(this.owner);
        this.owner.register('service:abilities', AbilitiesStub);

        this.uploadFails = false;
        this.owner.register(
            'service:fetch',
            class extends Service {
                uploadFile = {
                    perform: async (file, options, onSuccess) => {
                        calls.push(['uploadFile', file.name, options]);
                        if (test.uploadFails) {
                            throw new Error('storage offline');
                        }
                        onSuccess({ id: 'file_1', url: 'https://cdn.example.com/photo.png' });
                    },
                };
            }
        );
        this.owner.register(
            'service:current-user',
            class extends Service {
                companyId = 'company_1';

                // ember-ui's CurrencySelect and MoneyInput both read the whois option.
                getOption(key, defaultValue = null) {
                    return key === 'whois' ? { currency: { code: 'USD' } } : defaultValue;
                }
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
    });

    test('it renders the equipment form panels and options', async function (assert) {
        this.set('resource', makeRecord('equipment', { name: 'Forklift 7', type: 'forklift', status: 'available' }));

        await render(hbs`<Equipment::Form @resource={{this.resource}} />`);

        assert.dom().includesText('Assignment');
        assert.dom().includesText('Asset Type');
        assert.dom('.input-group').exists();
        assert.notOk(group('Asset'), 'no asset select until a type is chosen');
    });

    test('a stored equipable type preselects the asset type and reveals the matching asset select', async function (assert) {
        for (const [equipableType, label, modelName] of [
            ['fleet-ops:vehicle', 'Vehicle', 'vehicle'],
            ['Fleetbase\\FleetOps\\Models\\Vehicle', 'Vehicle', 'vehicle'],
            ['fleet-ops:driver', 'Driver', 'driver'],
            ['Fleetbase\\FleetOps\\Models\\Driver', 'Driver', 'driver'],
        ]) {
            this.set('resource', makeRecord('equipment', { equipable_type: equipableType }));

            await render(hbs`<Equipment::Form @resource={{this.resource}} />`);

            assert.dom(group('Asset Type').querySelector('.ember-power-select-selected-item')).hasText(label, `${equipableType} selects ${label}`);
            assert.dom(`[data-test-model-select="${modelName}"]`).exists(`${equipableType} resolves the ${modelName} model`);
        }
    });

    test('an unknown equipable type selects nothing and hides the asset select', async function (assert) {
        this.set('resource', makeRecord('equipment', { equipable_type: 'fleet-ops:trailer' }));

        await render(hbs`<Equipment::Form @resource={{this.resource}} />`);

        assert.dom(group('Asset Type').querySelector('.ember-power-select-selected-item')).doesNotExist();
        assert.notOk(group('Asset'), 'an unmapped type resolves no model');
    });

    test('changing the asset type resets the equipable and assigning one stores it', async function (assert) {
        this.set('resource', makeRecord('equipment', { equipable_type: 'fleet-ops:vehicle', equipable_uuid: 'vehicle_9', equipable: { id: 'vehicle_9' } }));

        await render(hbs`<Equipment::Form @resource={{this.resource}} />`);

        await chooseAssetType('Driver');
        assert.strictEqual(this.resource.equipable_type, 'fleet-ops:driver');
        assert.strictEqual(this.resource.equipable_uuid, null, 'a stale association is cleared');
        assert.strictEqual(this.resource.equipable, null);
        assert.dom('[data-test-model-select="driver"]').exists();

        await click('[data-test-model-select="driver"]');
        assert.strictEqual(this.resource.equipable.id, 'picked_1');
        assert.strictEqual(this.resource.equipable_uuid, 'picked_1');

        await click('[data-test-model-select-clear="driver"]');
        assert.strictEqual(this.resource.equipable, null);
        assert.strictEqual(this.resource.equipable_uuid, null, 'clearing the select clears the uuid');
    });

    test('uploading a photo stores the file and reports a failure', async function (assert) {
        this.set('resource', makeRecord('equipment', { id: 'equipment_1' }));

        await render(hbs`<Equipment::Form @resource={{this.resource}} />`);

        await selectFiles('input[type="file"]', new File(['photo'], 'photo.png', { type: 'image/png' }));
        assert.deepEqual(this.calls, [
            [
                'uploadFile',
                'photo.png',
                {
                    path: 'uploads/company_1/equipment/equipment_1',
                    subject_uuid: 'equipment_1',
                    subject_type: 'fleet-ops:equipment',
                    type: 'equipment_photo',
                },
            ],
        ]);
        assert.strictEqual(this.resource.photo_uuid, 'file_1');
        assert.strictEqual(this.resource.photo_url, 'https://cdn.example.com/photo.png');

        this.uploadFails = true;
        this.calls.length = 0;
        await selectFiles('input[type="file"]', new File(['photo'], 'second.png', { type: 'image/png' }));
        assert.deepEqual(this.calls.at(-1), ['error', 'Unable to upload photo: storage offline']);
        assert.strictEqual(this.resource.photo_uuid, 'file_1', 'a failed upload leaves the stored photo alone');
    });

    test('the type and status selects write straight to the record', async function (assert) {
        this.set('resource', makeRecord('equipment', {}));

        await render(hbs`<Equipment::Form @resource={{this.resource}} />`);

        await click(group('Type').querySelector('.ember-power-select-trigger'));
        await click(find('.ember-power-select-option'));
        assert.strictEqual(this.resource.type, 'ppe', 'the first equipment type option is stored');

        await click(group('Status').querySelector('.ember-power-select-trigger'));
        await click(find('.ember-power-select-option'));
        assert.strictEqual(this.resource.status, 'available');
    });
});
