import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, find, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

function addressButton() {
    const buttons = findAll('button');
    const button = buttons.find((element) => /address/i.test(element.textContent));
    if (!button) {
        throw new Error('no address button among: ' + JSON.stringify(buttons.map((element) => element.textContent.trim())));
    }
    return button;
}

module('Integration | Component | contact/form', function (hooks) {
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
        this.owner.register(
            'service:contact-actions',
            class extends Service {
                editPlace(resource) {
                    calls.push(['editPlace', resource]);
                }

                createPlace(resource) {
                    calls.push(['createPlace', resource]);
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
            'service:abilities',
            class extends Service {
                can() {
                    return true;
                }

                cannot() {
                    return false;
                }
            }
        );
    });

    test('it renders the contact inputs and uploads a photo onto the record', async function (assert) {
        this.set('resource', makeRecord('contact', { id: 'contact_1', name: 'Ada', title: 'Ops', email: 'ada@example.com', internal_id: 'INT-1', has_place: false }));

        await render(hbs`<Contact::Form @resource={{this.resource}} />`);

        const values = findAll('input').map((element) => element.value);
        for (const value of ['Ada', 'Ops', 'ada@example.com', 'INT-1']) {
            assert.true(values.includes(value), `${value} is bound`);
        }
        assert.dom('[data-test-registry="fleet-ops:component:contact:form:details"]').exists();
        assert.dom('[data-test-custom-fields]').exists();

        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls[0][2], { path: 'uploads/company_1/contacts/contact_1', subject_uuid: 'contact_1', subject_type: 'fleet-ops:contact', type: 'contact_photo' });
        assert.strictEqual(this.resource.photo_uuid, 'file_1');
        assert.strictEqual(this.resource.photo_url, '/photo.png');

        this.uploadFails = true;
        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls.at(-1), ['error', 'Unable to upload photo: disk full']);
    });

    test('the address controls select, remove, edit or create the place', async function (assert) {
        this.set('resource', makeRecord('contact', { has_place: true, place: { id: 'place_1' } }, { isNew: false }));

        await render(hbs`<Contact::Form @resource={{this.resource}} />`);

        await click('[data-test-model-select="place"]');
        assert.strictEqual(this.resource.place_uuid, 'picked_1');
        await click('[data-test-model-select-clear="place"]');
        assert.strictEqual(this.resource.place_uuid, 'picked_1', 'clearing the select is ignored');

        await click(addressButton());
        assert.deepEqual(this.calls.at(-1), ['editPlace', this.resource]);

        await click(find('svg[data-icon="trash"]').closest('button'));
        assert.strictEqual(this.resource.place, null);
        assert.strictEqual(this.resource.place_uuid, null);

        this.set('resource', makeRecord('contact', { has_place: false }, { isNew: false }));
        await render(hbs`<Contact::Form @resource={{this.resource}} />`);
        assert.dom('svg[data-icon="trash"]').doesNotExist();
        await click(addressButton());
        assert.deepEqual(this.calls.at(-1), ['createPlace', this.resource]);
    });
});
