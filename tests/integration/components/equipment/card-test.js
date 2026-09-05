import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, find, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | equipment/card', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register(
            'service:equipment-actions',
            class extends Service {
                transition = {
                    view: (equipment) => calls.push(['view', equipment.id]),
                    edit: (equipment) => calls.push(['edit', equipment.id]),
                };
                delete = (equipment) => calls.push(['delete', equipment.id]);
            }
        );
    });

    test('it renders the equipment identity, attributes and footer actions', async function (assert) {
        this.set('resource', {
            id: 'equipment_1',
            name: 'Forklift 7',
            public_id: 'equipment_public_1',
            serial_number: 'SN-7',
            photo_url: 'https://cdn.example.com/forklift.png',
            type: 'lift_truck',
            status: 'in_service',
            year: 2021,
            updatedAt: '2 Sep 2026',
        });

        await render(hbs`<Equipment::Card @resource={{this.resource}} class="probe" />`);

        assert.dom('.probe').exists();
        assert.dom().includesText('Forklift 7');
        assert.dom().includesText('SN-7');
        assert.dom().includesText('Lift truck');
        assert.dom().includesText('In service');
        assert.dom().includesText('2021');
        assert.dom().includesText('Last Modified: 2 Sep 2026');
        assert.dom('img').hasAttribute('src', 'https://cdn.example.com/forklift.png');

        const buttons = findAll('.btn-wrapper button');
        assert.strictEqual(buttons.length, 3, 'view, edit and delete');
        await click(buttons[0]);
        await click(buttons[1]);
        await click(buttons[2]);
        assert.deepEqual(this.calls, [
            ['view', 'equipment_1'],
            ['edit', 'equipment_1'],
            ['delete', 'equipment_1'],
        ]);
    });

    test('it falls back to the public id and omits the optional attribute rows', async function (assert) {
        this.set('resource', { id: 'equipment_2', public_id: 'equipment_public_2' });

        await render(hbs`<Equipment::Card @resource={{this.resource}} />`);

        assert.dom().includesText('equipment_public_2');
        assert.dom('svg[data-icon="tag"]').doesNotExist('no type row without a type');
        assert.dom('svg[data-icon="calendar"]').doesNotExist('no year row without a year');
        assert.ok(find('img').getAttribute('src').startsWith('data:image/svg+xml'), 'no photo falls back to the configured equipment image');
    });

    test('it yields its header, body and footer blocks', async function (assert) {
        this.set('resource', { id: 'equipment_3', name: 'Crane 2' });

        await render(hbs`
            <Equipment::Card @resource={{this.resource}}>
                <:header><span data-test-header-block>header block</span></:header>
                <:body><span data-test-body-block>body block</span></:body>
                <:footer><span data-test-footer-block>footer block</span></:footer>
            </Equipment::Card>
        `);

        assert.dom('[data-test-header-block]').hasText('header block');
        assert.dom('[data-test-body-block]').hasText('body block');
        assert.dom('[data-test-footer-block]').hasText('footer block');
    });
});
