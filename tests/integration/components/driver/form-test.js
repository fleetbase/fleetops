import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

const TEXT_INPUTS = 'input:not([data-test-date-picker]):not([data-test-phone-input]):not([data-test-unit-input])';

function fakeModal() {
    const modal = { events: [] };
    modal.startLoading = () => modal.events.push('startLoading');
    modal.stopLoading = () => modal.events.push('stopLoading');
    modal.done = () => modal.events.push('done');
    return modal;
}

function createUserButton() {
    return document.querySelector('svg[data-icon="user-plus"]').closest('button');
}

module('Integration | Component | driver/form', function (hooks) {
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
        registerTemplateOnly(this.owner, 'multi-select', hbs`<div data-test-multi-select disabled={{@disabled}}></div>`);
        const calls = (this.calls = []);
        const test = this;
        this.uploadFails = false;
        this.saveFails = false;
        this.allowWrite = true;
        this.modals = [];
        const modals = this.modals;
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
                success(message) {
                    calls.push(['success', message]);
                }

                error(message) {
                    calls.push(['error', message]);
                }

                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
        this.owner.register(
            'service:modals-manager',
            class extends Service {
                show(name, options) {
                    modals.push({ name, options });
                }
            }
        );
        this.owner.register(
            'service:universe/extension-manager',
            class extends Service {
                async ensureEngineLoaded(name) {
                    calls.push(['ensureEngineLoaded', name]);
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
        // The dummy app has no `user` model; the action only needs a record-like object back.
        const store = this.owner.lookup('service:store');
        store.createRecord = (type, attributes) => {
            calls.push(['createRecord', type, attributes]);
            const record = { id: 'user_new', slug: 'new-user', ...attributes };
            record.setProperties = (values) => Object.assign(record, values);
            record.save = async () => {
                calls.push(['save', type]);
                if (test.saveFails) {
                    throw new Error('email taken');
                }
                return record;
            };
            return record;
        };
    });

    test('it renders the driver bound to every section and applies the edits', async function (assert) {
        this.set(
            'resource',
            makeRecord(
                'driver',
                {
                    id: 'driver_1',
                    user: { id: 'user_1', name: 'Sam Driver', email: 'sam@example.test', phone: '+15550100' },
                    internal_id: 'INT-1',
                    drivers_license_number: 'DL-123',
                    city: 'Austin',
                    country: 'US',
                    status: 'available',
                    max_travel_time: '28800',
                    max_distance: '150000',
                },
                { isNew: false }
            )
        );

        await render(hbs`<Driver::Form @resource={{this.resource}} />`);

        assert.deepEqual(
            findAll(TEXT_INPUTS).map((input) => [input.value, input.disabled]),
            [
                ['Sam Driver', true],
                ['sam@example.test', true],
                ['INT-1', false],
                ['DL-123', false],
                ['Austin', false],
                ['28800', false],
                ['150000', false],
            ]
        );
        assert.dom('[data-test-phone-input]').hasValue('+15550100');
        assert.dom('[data-test-model-select="user"]').exists();
        assert.dom('[data-test-model-select="vendor"]').isNotDisabled();
        assert.dom('[data-test-model-select="vehicle"]').isNotDisabled();
        assert.dom('[data-test-date-picker]').exists({ count: 1 });
        assert.dom('[data-test-country-select]').exists();
        assert.dom('.ember-power-select-trigger').exists({ count: 1 });
        assert.dom('[data-test-coordinates]').exists();
        assert.dom('[data-test-multi-select]').exists();
        assert.dom('[data-test-avatar-picker]').exists();
        assert.dom('[data-test-metadata]').exists();
        assert.dom('[data-test-custom-fields]').exists();
        assert.deepEqual(
            findAll('[data-test-registry]').map((element) => element.getAttribute('data-test-registry')),
            ['fleet-ops:component:driver:form:details', 'fleet-ops:component:driver:form']
        );
        assert.dom().includesText('Available');

        await click('[data-test-model-select="vendor"]');
        assert.strictEqual(this.resource.vendor.id, 'picked_1');
        await click('[data-test-model-select="vehicle"]');
        assert.strictEqual(this.resource.vehicle.id, 'picked_1');
        await click('[data-test-model-select-clear="user"]');
        assert.strictEqual(this.resource.user, null);
        assert.dom(TEXT_INPUTS).exists({ count: 5 }, 'the user details disappear without a user');
        await click('[data-test-model-select="user"]');
        assert.strictEqual(this.resource.user.name, 'Picked');
        assert.dom(TEXT_INPUTS).exists({ count: 7 });

        await click('.ember-power-select-trigger');
        await click(findAll('.ember-power-select-option')[1]);
        assert.strictEqual(this.resource.status, 'inactive');

        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls.at(-1)[2], { path: 'uploads/company_1/drivers/driver_1', subject_uuid: 'driver_1', subject_type: 'fleet-ops:driver', type: 'driver_photo' });
        assert.strictEqual(this.resource.photo_uuid, 'file_1');
        assert.strictEqual(this.resource.photo_url, '/photo.png');

        this.uploadFails = true;
        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls.at(-1), ['error', 'Unable to upload photo: disk full']);
    });

    test('the user-account action creates a user through the IAM modal', async function (assert) {
        this.set('resource', makeRecord('driver', { id: 'driver_2' }, { isNew: false }));

        await render(hbs`<Driver::Form @resource={{this.resource}} />`);

        await click(createUserButton());
        assert.deepEqual(this.calls, [
            ['ensureEngineLoaded', '@fleetbase/iam-engine'],
            ['createRecord', 'user', { status: 'pending', type: 'user' }],
        ]);
        assert.strictEqual(this.modals.length, 1);
        const { name, options } = this.modals[0];
        assert.strictEqual(name, 'modals/user-form');
        assert.strictEqual(options.title, 'Create a new user');
        assert.strictEqual(options.formPermission, 'iam create user');
        assert.strictEqual(options.user.status, 'pending');

        options.uploadNewPhoto({ name: 'avatar.png' });
        assert.deepEqual(this.calls.at(-1), [
            'upload',
            { name: 'avatar.png' },
            { path: 'uploads/company_1/users/new-user', subject_uuid: 'user_new', subject_type: 'user', type: 'user_photo' },
        ]);
        await Promise.resolve();
        assert.strictEqual(options.user.avatar_uuid, 'file_1');
        assert.strictEqual(options.user.avatar_url, '/photo.png');

        const modal = fakeModal();
        await options.confirm(modal);
        assert.deepEqual(this.calls.slice(-2), [
            ['save', 'user'],
            ['success', 'New user created successfully!'],
        ]);
        assert.deepEqual(modal.events, ['startLoading', 'done']);

        this.saveFails = true;
        const failing = fakeModal();
        await options.confirm(failing);
        assert.deepEqual(this.calls.at(-1), ['serverError', 'email taken']);
        assert.deepEqual(failing.events, ['startLoading', 'stopLoading']);
    });

    test('without write access the fields and pickers are disabled', async function (assert) {
        this.allowWrite = false;
        this.set('resource', makeRecord('driver', { id: 'driver_3', internal_id: 'INT-3' }, { isNew: false }));

        await render(hbs`<Driver::Form @resource={{this.resource}} />`);

        assert.strictEqual(findAll(`${TEXT_INPUTS}[disabled]`).length, 5, 'every driver text input honours cannot-write');
        assert.dom('.ember-power-select-trigger').hasAttribute('aria-disabled', 'true');
        assert.dom('[data-test-upload-button]').isDisabled();
        assert.dom('[data-test-model-select="vendor"]').isDisabled();
        assert.dom('[data-test-model-select="vehicle"]').isDisabled();
        assert.dom('[data-test-date-picker]').isDisabled();
        assert.dom('[data-test-coordinates]').hasAttribute('disabled');
        assert.dom('[data-test-multi-select]').hasAttribute('disabled');
    });
});
