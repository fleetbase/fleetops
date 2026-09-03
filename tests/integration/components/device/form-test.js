import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | device/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        registerTemplateOnly(
            this.owner,
            'upload-button',
            hbs`<button type="button" data-test-upload-button disabled={{@disabled}} {{on "click" (fn @onFileAdded (hash name="photo.png"))}}>{{@buttonText}}</button>`
        );
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

    test('it renders every section bound to the device and applies the selections', async function (assert) {
        this.set(
            'resource',
            makeRecord(
                'device',
                {
                    id: 'device_1',
                    name: 'Tracker One',
                    device_id: 'IMEI-1',
                    internal_id: 'INT-1',
                    provider: 'flespi',
                    model: 'FMB920',
                    manufacturer: 'Teltonika',
                    serial_number: 'SN-1',
                    location: 'Under dash',
                    notes: 'Installed by Sam',
                    data_frequency: '30s',
                    type: 'gps_tracker',
                    status: 'active',
                },
                { isNew: false }
            )
        );

        await render(hbs`<Device::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-model-select="telematic"]').isNotDisabled();
        assert.dom('[data-test-model-select="warranty"]').exists();
        assert.dom('[data-test-date-picker]').exists({ count: 2 });
        assert.dom('[data-test-custom-fields]').exists();
        assert.dom('[data-test-registry="fleet-ops:component:device:form"]').exists();
        assert.dom('.ember-power-select-trigger').exists({ count: 2 });
        assert.dom('.ember-power-select-selected-item').exists({ count: 2 });
        assert.dom().includesText('GPS Tracker');
        assert.dom().includesText('Active');
        assert.dom('textarea').hasValue('Installed by Sam');
        assert.deepEqual(
            findAll('input').map((input) => input.value),
            ['30s', 'Tracker One', 'IMEI-1', 'INT-1', 'flespi', 'FMB920', 'Teltonika', 'SN-1', 'Under dash', '', '']
        );
        assert.dom('input[disabled]').doesNotExist();

        await click('[data-test-model-select="telematic"]');
        assert.strictEqual(this.resource.telematic_uuid, 'picked_1');
        assert.strictEqual(this.resource.telematic.name, 'Picked');

        await click('[data-test-model-select="warranty"]');
        assert.strictEqual(this.resource.warranty.id, 'picked_1');

        await click(findAll('.ember-power-select-trigger')[0]);
        await click(findAll('.ember-power-select-option')[1]);
        assert.strictEqual(this.resource.type, 'obd2_plugin');

        await click(findAll('.ember-power-select-trigger')[1]);
        await click(findAll('.ember-power-select-option')[0]);
        assert.strictEqual(this.resource.status, 'never_connected');

        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls[0][2], { path: 'uploads/company_1/devices/device_1', subject_uuid: 'device_1', subject_type: 'fleet-ops:device', type: 'device_photo' });
        assert.strictEqual(this.resource.photo_uuid, 'file_1');
        assert.strictEqual(this.resource.photo_url, '/photo.png');

        this.uploadFails = true;
        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls.at(-1), ['error', 'Unable to upload photo: disk full']);
    });

    test('a persisted provider-synced device locks the telematic select', async function (assert) {
        this.set('resource', makeRecord('device', { id: 'device_2', telematic_uuid: 'telematic_1' }, { isNew: false }));

        await render(hbs`<Device::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-model-select="telematic"]').isDisabled();
        assert.dom('[data-test-model-select="warranty"]').isNotDisabled();
    });

    test('every input is disabled without write access', async function (assert) {
        this.allowWrite = false;
        this.set('resource', makeRecord('device', { id: 'device_3', name: 'Plain' }, { isNew: false }));

        await render(hbs`<Device::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-model-select="telematic"]').isDisabled();
        assert.dom('[data-test-upload-button]').isDisabled();
        assert.dom('textarea').isDisabled();
        assert.strictEqual(findAll('input:not([disabled])').length, 0);
    });

    test('a persisted device with only a telematic relationship is locked, a new one is not', async function (assert) {
        this.set('resource', makeRecord('device', { id: 'device_4', telematic: { id: 'telematic_2' } }, { isNew: false }));

        await render(hbs`<Device::Form @resource={{this.resource}} />`);
        assert.dom('[data-test-model-select="telematic"]').isDisabled();

        this.set('resource', makeRecord('device', { id: 'device_5', telematic_uuid: 'telematic_1' }, { isNew: true }));
        await render(hbs`<Device::Form @resource={{this.resource}} />`);
        assert.dom('[data-test-model-select="telematic"]').isNotDisabled();
    });
});
