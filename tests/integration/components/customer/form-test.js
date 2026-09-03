import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

function buttonByText(pattern) {
    return findAll('button').find((button) => pattern.test(button.textContent));
}

module('Integration | Component | customer/form', function (hooks) {
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
        this.installed = [];
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
            'service:universe/extension-manager',
            class extends Service {
                isInstalled(name) {
                    return test.installed.includes(name);
                }
            }
        );
        this.owner.register(
            'service:customer-actions',
            class extends Service {
                editPlace(resource) {
                    calls.push(['editPlace', resource.id]);
                }

                createPlace(resource) {
                    calls.push(['createPlace', resource.id]);
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

    test('it renders the customer and manages the address', async function (assert) {
        this.set(
            'resource',
            makeRecord(
                'contact',
                {
                    id: 'customer_1',
                    name: 'Acme',
                    title: 'Buyer',
                    email: 'buyer@example.test',
                    phone: '+15550100',
                    internal_id: 'INT-1',
                    has_place: true,
                    place: { id: 'place_1' },
                    place_uuid: 'place_1',
                },
                { isNew: false }
            )
        );

        await render(hbs`<Customer::Form @resource={{this.resource}} />`);

        assert.deepEqual(
            findAll('input:not([data-test-phone-input])').map((input) => input.value),
            ['Acme', 'Buyer', 'buyer@example.test', 'INT-1']
        );
        assert.dom('[data-test-phone-input]').hasValue('+15550100');
        assert.dom('[data-test-custom-fields]').exists();
        assert.deepEqual(
            findAll('[data-test-registry]').map((element) => element.getAttribute('data-test-registry')),
            ['fleet-ops:component:customer:form:details', 'fleet-ops:component:customer:form']
        );
        assert.dom('.fleetbase-checkbox').doesNotExist('an existing customer gets no welcome email option');

        await click(buttonByText(/Edit/));
        assert.deepEqual(this.calls, [['editPlace', 'customer_1']]);

        await click(buttonByText(/Remove/));
        assert.strictEqual(this.resource.place, null);
        assert.strictEqual(this.resource.place_uuid, null);

        await click('[data-test-model-select="place"]');
        assert.strictEqual(this.resource.place.id, 'picked_1');
        assert.strictEqual(this.resource.place_uuid, 'picked_1');
        await click('[data-test-model-select-clear="place"]');
        assert.strictEqual(this.resource.place_uuid, 'picked_1', 'clearing the select is ignored');

        this.set('resource', makeRecord('contact', { id: 'customer_2', has_place: false }, { isNew: false }));
        await settled();
        assert.notOk(buttonByText(/Remove/), 'no remove button without a place');
        await click(buttonByText(/New Address/));
        assert.deepEqual(this.calls.at(-1), ['createPlace', 'customer_2']);

        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls.at(-1)[2], { path: 'uploads/company_1/contacts/customer_2', subject_uuid: 'customer_2', subject_type: 'fleet-ops:contact', type: 'contact_photo' });
        assert.strictEqual(this.resource.photo_uuid, 'file_1');
        assert.strictEqual(this.resource.photo_url, '/photo.png');

        this.uploadFails = true;
        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls.at(-1), ['error', 'Unable to upload photo: disk full']);
    });

    test('the welcome email option is offered for a new customer with the portal installed and stored on the meta', async function (assert) {
        this.installed = ['@fleetbase/customer-portal-engine'];
        this.set('resource', makeRecord('contact', { id: 'customer_3' }, { isNew: true }));

        await render(hbs`<Customer::Form @resource={{this.resource}} />`);

        assert.dom('.fleetbase-checkbox').exists({ count: 1 });
        assert.dom('.fleetbase-checkbox').isNotChecked('the welcome email is opt in');
        assert.dom().includesText('Send customer portal welcome email');

        await click('.fleetbase-checkbox');
        assert.deepEqual(this.resource.meta, { customer_portal: { send_welcome_email: true } });
        assert.dom('.fleetbase-checkbox').isChecked();

        this.resource.set('meta', { note: 'kept', customer_portal: { theme: 'dark', send_welcome_email: true } });
        await render(hbs`<Customer::Form @resource={{this.resource}} />`);
        assert.dom('.fleetbase-checkbox').isChecked();
        await click('.fleetbase-checkbox');
        assert.deepEqual(this.resource.meta, { note: 'kept', customer_portal: { theme: 'dark', send_welcome_email: false } });
    });

    test('the welcome email option is hidden without the portal extension', async function (assert) {
        this.set('resource', makeRecord('contact', { id: 'customer_4' }, { isNew: true }));

        await render(hbs`<Customer::Form @resource={{this.resource}} />`);

        assert.dom('.fleetbase-checkbox').doesNotExist();
        assert.dom().doesNotIncludeText('Send customer portal welcome email');
    });

    test('without write access the inputs, buttons and select are disabled', async function (assert) {
        this.allowWrite = false;
        this.set('resource', makeRecord('contact', { id: 'customer_5', has_place: true, place: { id: 'place_1' } }, { isNew: false }));

        await render(hbs`<Customer::Form @resource={{this.resource}} />`);

        assert.strictEqual(findAll('input:not([disabled])').length, 0);
        assert.dom('[data-test-upload-button]').isDisabled();
        assert.dom('[data-test-model-select="place"]').isDisabled();
        assert.ok(buttonByText(/Remove/).disabled);
        assert.ok(buttonByText(/Edit/).disabled);
    });
});
