import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | part/card', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register(
            'service:part-actions',
            class extends Service {
                transition = {
                    view: (part) => calls.push(['view', part.id]),
                    edit: (part) => calls.push(['edit', part.id]),
                };
                delete = (part) => calls.push(['delete', part.id]);
            }
        );
    });

    test('it renders the part identity, inventory attributes and footer actions', async function (assert) {
        this.set('resource', {
            id: 'part_1',
            name: 'Brake pad',
            public_id: 'part_public_1',
            part_number: 'BP-100',
            photo_url: 'https://cdn.example.com/pad.png',
            type: 'consumable',
            quantity_on_hand: 12,
            unit_cost: 4500,
            currency: 'USD',
            updatedAt: '2 Sep 2026',
        });

        await render(hbs`<Part::Card @resource={{this.resource}} class="probe" />`);

        assert.dom('.probe').exists();
        assert.dom().includesText('Brake pad');
        assert.dom().includesText('BP-100');
        assert.dom().includesText('Consumable');
        assert.dom().includesText('Qty: 12');
        assert.dom().includesText('$45.00');
        assert.dom().includesText('Last Modified: 2 Sep 2026');
        assert.dom('img').hasAttribute('src', 'https://cdn.example.com/pad.png');

        const buttons = findAll('.btn-wrapper button');
        assert.strictEqual(buttons.length, 3, 'view, edit and delete');
        await click(buttons[0]);
        await click(buttons[1]);
        await click(buttons[2]);
        assert.deepEqual(this.calls, [
            ['view', 'part_1'],
            ['edit', 'part_1'],
            ['delete', 'part_1'],
        ]);
    });

    test('it falls back through sku to the public id and omits the optional rows', async function (assert) {
        this.set('resource', { id: 'part_2', public_id: 'part_public_2', sku: 'SKU-2' });

        await render(hbs`<Part::Card @resource={{this.resource}} />`);

        assert.dom().includesText('part_public_2', 'the title falls back to the public id');
        assert.dom().includesText('SKU-2', 'the subtitle falls back to the sku');
        assert.dom().doesNotIncludeText('Qty:');
        assert.dom('svg[data-icon="tag"]').doesNotExist('no type row without a type');
    });

    test('it yields its header, body and footer blocks', async function (assert) {
        this.set('resource', { id: 'part_3', name: 'Filter' });

        await render(hbs`
            <Part::Card @resource={{this.resource}}>
                <:header><span data-test-header-block>header block</span></:header>
                <:body><span data-test-body-block>body block</span></:body>
                <:footer><span data-test-footer-block>footer block</span></:footer>
            </Part::Card>
        `);

        assert.dom('[data-test-header-block]').hasText('header block');
        assert.dom('[data-test-body-block]').hasText('body block');
        assert.dom('[data-test-footer-block]').hasText('footer block');
    });
});
