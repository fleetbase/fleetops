import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

function badges() {
    return findAll('[data-test-resource-identity-meta-badge]').map((element) => element.textContent.trim());
}

module('Integration | Component | cell/part-identity', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the part with its type and a low stock badge', async function (assert) {
        this.set('part', { name: 'Brake Pad', type: 'Filter', is_low_stock: true, is_in_stock: true, photo_url: '/pad.png' });

        await render(hbs`<Cell::PartIdentity @row={{this.part}} @column={{(hash)}} />`);

        assert.dom(this.element).includesText('Brake Pad');
        assert.deepEqual(badges(), ['Filter', 'Low Stock'], 'low stock wins over in stock');
        assert.dom('[data-test-resource-identity-status-badge]').doesNotExist('the status only tones the dot; it is never printed');
    });

    test('it labels in-stock and out-of-stock parts', async function (assert) {
        this.set('part', { name: 'Oil Filter', is_in_stock: true });
        await render(hbs`<Cell::PartIdentity @row={{this.part}} @column={{(hash)}} />`);
        assert.deepEqual(badges(), ['In Stock']);

        this.set('part', { name: 'Wiper', is_in_stock: false });
        await render(hbs`<Cell::PartIdentity @row={{this.part}} @column={{(hash)}} />`);
        assert.deepEqual(badges(), ['Out Of Stock']);
    });

    test('it resolves the part through a resource path and merges column overrides', async function (assert) {
        this.set('row', { part: { name: 'Nested Part', type: 'Belt' } });

        await render(hbs`<Cell::PartIdentity @row={{this.row}} @column={{hash resourcePath="part" showStatusBadge=true}} />`);

        assert.dom(this.element).includesText('Nested Part');
        assert.deepEqual(badges(), ['Belt', 'Out Of Stock']);
    });

    test('it renders without any column configuration', async function (assert) {
        this.set('part', { name: 'Bare Part' });
        await render(hbs`<Cell::PartIdentity @row={{this.part}} />`);
        assert.dom(this.element).includesText('Bare Part');
    });

    test('it shows the empty text when no part resolves', async function (assert) {
        this.set('row', { part: null });

        await render(hbs`<Cell::PartIdentity @row={{this.row}} @column={{hash resourcePath="part"}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('-');

        await render(hbs`<Cell::PartIdentity @row={{this.row}} @column={{hash resourcePath="part" emptyText="No part"}} />`);
        assert.dom('[data-test-identity-empty-text]').hasText('No part');
    });
});
