import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | part/panel-header', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the part identity and the panel header actions', async function (assert) {
        const calls = [];
        this.set('resource', {
            name: 'Brake pad',
            status: 'active',
            type: 'consumable',
            part_number: 'BP-100',
            photo_url: 'https://cdn.example.com/pad.png',
        });
        this.set('actionButtons', [{ text: 'Refresh', onClick: () => calls.push('refresh') }]);

        await render(hbs`<Part::PanelHeader @resource={{this.resource}} @actionButtons={{this.actionButtons}} />`);

        assert.dom('h1').hasText('Brake pad');
        assert.dom('.status-badge').includesText('Active');
        assert.dom().includesText('Consumable');
        assert.dom().includesText('BP-100');
        assert.dom('img').hasAttribute('src', 'https://cdn.example.com/pad.png');

        await click(findAll('button').find((button) => /Refresh/.test(button.textContent)));
        assert.deepEqual(calls, ['refresh']);
    });

    test('a record with no part number falls back to a dash', async function (assert) {
        this.set('resource', { name: 'Filter', status: 'active' });

        await render(hbs`<Part::PanelHeader @resource={{this.resource}} />`);

        assert.dom('h1').hasText('Filter');
        assert.dom().includesText('-');
    });
});
