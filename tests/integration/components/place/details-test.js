import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | place/details', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'custom-field/yield', hbs`<div data-test-custom-fields></div>`);
        registerTemplateOnly(this.owner, 'country-name', hbs`<span data-test-country>{{@country}}</span>`);
    });

    test('it renders the address fields, map and avatar', async function (assert) {
        this.set('resource', {
            id: 'place_1',
            name: 'Warehouse',
            displayName: 'Warehouse',
            address: '1 Harbour Road',
            street1: '1 Harbour Road',
            street2: 'Unit 4',
            neighborhood: 'Docklands',
            building: 'Block C',
            security_access_code: '1234',
            city: 'Singapore',
            province: 'Central',
            country: 'SG',
            phone: '+6512345678',
            latitude: 1.3,
            longitude: 103.8,
            location: { type: 'Point', coordinates: [103.8, 1.3] },
            avatar_url: '/avatar.png',
        });

        await render(hbs`<Place::Details @resource={{this.resource}} />`);

        for (const text of ['Warehouse', '1 Harbour Road', 'Unit 4', 'Docklands', 'Block C', '1234', 'Singapore', 'Central', '+6512345678']) {
            assert.dom(this.element).includesText(text);
        }
        assert.dom('[data-test-country]').hasText('SG');
        assert.dom('.leaflet-container').exists('the location map renders');
        assert.dom('.leaflet-marker-icon').exists('the place is marked');
        assert.dom('[data-test-custom-fields]').exists();
        assert.dom('img[alt="Warehouse"]').exists('the avatar renders');
    });

    test('empty fields render as dashes', async function (assert) {
        this.set('resource', { id: 'place_2', latitude: 0, longitude: 0 });

        await render(hbs`<Place::Details @resource={{this.resource}} />`);

        assert.strictEqual(findAll('.field-value').filter((element) => element.textContent.trim() === '-').length, 9, 'every text field is dashed');
    });
});
