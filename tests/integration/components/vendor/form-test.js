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

module('Integration | Component | vendor/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        registerTemplateOnly(
            this.owner,
            'fetch-select',
            hbs`<button type="button" data-test-fetch-select={{@endpoint}} disabled={{@disabled}} {{on "click" (fn @onChange (hash code="shippo" name="Shippo"))}}></button><button type="button" data-test-fetch-select-clear {{on "click" (fn @onChange null)}}></button>`
        );
        const calls = (this.calls = []);
        this.owner.register(
            'service:vendor-actions',
            class extends Service {
                createVendorIntegration(provider) {
                    calls.push(['createVendorIntegration', provider]);
                    return makeRecord('integrated-vendor', { provider_options: { name: provider.name, code: provider.code, credential_params: [], option_params: [] } });
                }

                editPlace(resource) {
                    calls.push(['editPlace', resource]);
                }

                createPlace(resource) {
                    calls.push(['createPlace', resource]);
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

    test('a new vendor picks its type, and an integrated type wires up a provider integration', async function (assert) {
        const created = [];
        this.set('resource', makeRecord('vendor', { isIntegratedVendor: true }));
        this.set('onIntegrationCreated', (integration) => created.push(integration));

        await render(hbs`<Vendor::Form @resource={{this.resource}} @onIntegrationCreated={{this.onIntegrationCreated}} />`);

        assert.dom('[data-test-fetch-select="integrated-vendors/supported"]').exists('an integrated vendor chooses a provider');
        assert.dom('.form-wrapper').doesNotIncludeText('Vendor Details');

        await click('[data-test-fetch-select-clear]');
        assert.deepEqual(created, [], 'clearing the provider does nothing');

        await click('[data-test-fetch-select="integrated-vendors/supported"]');
        assert.strictEqual(this.calls[0][0], 'createVendorIntegration');
        assert.strictEqual(created.length, 1, 'the integration is handed back');
        assert.dom('h3').hasText('Shippo', 'the integrated vendor form renders for the integration');

        await click('.ember-power-select-trigger');
        await click(findAll('.ember-power-select-option').find((element) => element.textContent.includes('Fuel Supplier')));
        assert.strictEqual(this.resource.type, 'fuel_supplier');
        assert.strictEqual(created.at(-1), null, 'a non-integrated type drops the integration');

        await click('.ember-power-select-trigger');
        await click(findAll('.ember-power-select-option').find((element) => element.textContent.includes('Integrated Vendor')));
        assert.strictEqual(this.resource.type, 'integrated_vendor');
        assert.strictEqual(created.length, 2, 'choosing the integrated type keeps the integration untouched');
    });

    test('an integration can be created without an @onIntegrationCreated callback', async function (assert) {
        this.set('resource', makeRecord('vendor', { isIntegratedVendor: true }));

        await render(hbs`<Vendor::Form @resource={{this.resource}} />`);
        await click('[data-test-fetch-select="integrated-vendors/supported"]');

        assert.dom('h3').hasText('Shippo');
    });

    test('a regular vendor edits its details and manages its address', async function (assert) {
        this.set('resource', makeRecord('vendor', { name: 'Acme', email: 'ops@acme.test', website_url: 'https://acme.test', has_place: true, place: { id: 'place_1' } }, { isNew: false }));

        await render(hbs`<Vendor::Form @resource={{this.resource}} />`);

        assert.dom('[data-test-fetch-select]').doesNotExist('a persisted vendor has no setup panel');
        const values = findAll('input').map((element) => element.value);
        assert.true(values.includes('Acme') && values.includes('ops@acme.test') && values.includes('https://acme.test'));
        assert.dom('[data-test-country-select]').exists();
        assert.dom('[data-test-custom-fields]').exists();

        await click(find('svg[data-icon="trash"]').closest('button'));
        assert.strictEqual(this.resource.place, null);
        assert.strictEqual(this.resource.place_uuid, null);

        await click(addressButton());
        assert.deepEqual(this.calls.at(-1), ['editPlace', this.resource], 'a vendor with a place edits it');

        await click('[data-test-model-select="place"]');
        assert.strictEqual(this.resource.place_uuid, 'picked_1');
        await click('[data-test-model-select-clear="place"]');
        assert.strictEqual(this.resource.place_uuid, 'picked_1', 'clearing the select is ignored');
    });

    test('a vendor without an address creates one', async function (assert) {
        this.set('resource', makeRecord('vendor', { has_place: false }, { isNew: false }));

        await render(hbs`<Vendor::Form @resource={{this.resource}} />`);

        assert.dom('svg[data-icon="trash"]').doesNotExist();
        await click(addressButton());
        assert.deepEqual(this.calls.at(-1), ['createPlace', this.resource]);
    });
});
