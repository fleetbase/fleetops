import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | driver/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
        registerTemplateOnly(this.owner, 'country-name', hbs`<span data-test-country>{{@country}}</span>`);
        registerTemplateOnly(this.owner, 'registry-yield', hbs`<div data-test-registry={{@registry}}></div>`);
        registerTemplateOnly(this.owner, 'metadata-viewer', hbs`<div data-test-metadata>{{@metadata.note}}</div>`);
        const edited = (this.edited = []);
        this.owner.register(
            'service:resource-metadata',
            class extends Service {
                edit(resource) {
                    edited.push(resource);
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

    test('it renders the account, driver details, constraints and metadata, and edits the metadata', async function (assert) {
        this.set('resource', {
            name: 'Ada',
            email: 'ada@example.com',
            phone: '+65 1',
            public_id: 'driver_1',
            internal_id: 'INT-1',
            drivers_license_number: 'DL-1',
            vendor_name: 'Acme',
            city: 'Singapore',
            country: 'SG',
            location: { type: 'Point', coordinates: [103.8, 1.3] },
            skills: ['hazmat', 'forklift'],
            max_travel_time: 3600,
            max_distance: 50000,
            meta: { note: 'Night shift' },
        });

        await render(hbs`<Driver::Details @resource={{this.resource}} />`);

        for (const text of ['Ada', 'ada@example.com', '+65 1', 'driver_1', 'INT-1', 'DL-1', 'Acme', 'Singapore', 'hazmat, forklift', '3600 s', '50000 m', 'Night shift']) {
            assert.dom(this.element).includesText(text);
        }
        assert.dom('[data-test-country]').hasText('SG');
        assert.dom('[data-test-registry="fleet-ops:component:driver:details"]').exists();
        assert.dom('[data-test-custom-fields]').exists();

        await click(findAll('button').find((element) => element.textContent.includes('Edit')));
        assert.deepEqual(this.edited, [this.resource], 'the metadata panel action edits the driver metadata');
    });

    test('the constraints panel is skipped without constraints and empty fields are dashed', async function (assert) {
        this.set('resource', { skills: [], meta: {} });

        await render(hbs`<Driver::Details @resource={{this.resource}} />`);

        assert.dom(this.element).doesNotIncludeText('Orchestrator Constraints');
        assert.true(findAll('.field-value, .click-to-copy--value').filter((element) => element.textContent.trim() === '-').length >= 7, 'the text fields are dashed');

        this.set('resource', { max_distance: 10 });
        await render(hbs`<Driver::Details @resource={{this.resource}} />`);
        assert.dom(this.element).includesText('Orchestrator Constraints').includesText('10 m').doesNotIncludeText('Max Driving Time');
    });
});
