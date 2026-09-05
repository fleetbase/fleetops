import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | entity/form', function (hooks) {
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
            'service:fetch',
            class extends Service {
                uploadFile = {
                    perform: async (file, options, callback) => {
                        calls.push(['upload', options]);
                        if (test.uploadFails) {
                            throw new Error('too large');
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

    test('a known entity type renders in the select and can be changed', async function (assert) {
        this.set('resource', makeRecord('entity', { id: 'entity_1', name: 'Parcel A', internal_id: 'INT-1', sku: 'SKU-1', description: 'Fragile', type: 'parcel' }));

        await render(hbs`<Entity::Form @resource={{this.resource}} />`);

        const values = findAll('input').map((element) => element.value);
        for (const value of ['Parcel A', 'INT-1', 'SKU-1']) {
            assert.true(values.includes(value), `${value} is bound`);
        }
        assert.dom('textarea').hasValue('Fragile');
        assert.dom('.ember-power-select-trigger').includesText('Parcel', 'the known type is selected');
        assert.dom('input[type="checkbox"]').isNotChecked();
        assert.strictEqual(findAll('[data-test-money-input]').length, 3);
        assert.strictEqual(findAll('[data-test-unit-input]').length, 4);
        assert.dom('[data-test-registry="fleet-ops:component:entity:form"]').exists();

        await click('.ember-power-select-trigger');
        await click(findAll('.ember-power-select-option').find((element) => element.textContent.includes('Package')));
        assert.strictEqual(this.resource.type, 'package');
    });

    test('an unknown type starts in custom mode; toggling custom mode off clears it, toggling on keeps it', async function (assert) {
        this.set('resource', makeRecord('entity', { type: 'zeppelin_pod' }));

        await render(hbs`<Entity::Form @resource={{this.resource}} />`);

        assert.dom('input[type="checkbox"]').isChecked('a custom type starts in custom mode');
        assert.true(
            findAll('input').some((element) => element.value === 'zeppelin_pod'),
            'the custom type is editable as text'
        );
        assert.dom('.ember-power-select-trigger').doesNotExist();

        await click('input[type="checkbox"]');
        assert.dom('.ember-power-select-trigger').exists('the select is back');
        assert.strictEqual(this.resource.type, null, 'an unknown type is cleared when leaving custom mode');

        await click('input[type="checkbox"]');
        assert.dom('.ember-power-select-trigger').doesNotExist();
        assert.strictEqual(this.resource.type, null);
    });

    test('a known type survives leaving custom mode', async function (assert) {
        this.set('resource', makeRecord('entity', { type: 'parcel' }));

        await render(hbs`<Entity::Form @resource={{this.resource}} />`);
        await click('input[type="checkbox"]');
        await click('input[type="checkbox"]');

        assert.strictEqual(this.resource.type, 'parcel');
    });

    test('photo uploads land on the record and failures are reported', async function (assert) {
        this.set('resource', makeRecord('entity', { id: 'entity_1' }));

        await render(hbs`<Entity::Form @resource={{this.resource}} />`);

        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls[0][1], { path: 'uploads/company_1/entities/entity_1', subject_uuid: 'entity_1', subject_type: 'fleet-ops:entity', type: 'entity_photo' });
        assert.strictEqual(this.resource.photo_url, '/photo.png');

        this.uploadFails = true;
        await click('[data-test-upload-button]');
        assert.deepEqual(this.calls.at(-1), ['error', 'Unable to upload photo: too large']);
    });
});
