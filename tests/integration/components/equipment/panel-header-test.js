import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, find, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | equipment/panel-header', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the equipment identity and the panel header actions', async function (assert) {
        const calls = [];
        this.set('resource', {
            name: 'Forklift 7',
            status: 'in_service',
            type: 'lift_truck',
            serial_number: 'SN-7',
            photo_url: 'https://cdn.example.com/forklift.png',
        });
        this.set('actionButtons', [{ text: 'Refresh', onClick: () => calls.push('refresh') }]);
        this.set('onPressCancel', () => calls.push('cancel'));

        await render(hbs`<Equipment::PanelHeader @resource={{this.resource}} @actionButtons={{this.actionButtons}} @onPressCancel={{this.onPressCancel}} />`);

        assert.dom('h1').hasText('Forklift 7');
        assert.dom('.status-badge').includesText('In Service');
        assert.dom().includesText('Lift Truck');
        assert.dom().includesText('SN-7');
        assert.dom('img').hasAttribute('src', 'https://cdn.example.com/forklift.png');
        assert.dom('img').hasAttribute('alt', 'Forklift 7');

        await click(findAll('button').find((button) => /Refresh/.test(button.textContent)));
        await click('.next-content-overlay-panel-cancel-button');
        assert.deepEqual(calls, ['refresh', 'cancel']);
    });

    test('a serial-less record falls back to a dash and the placeholder image', async function (assert) {
        this.set('resource', { name: 'Crane 2', status: 'retired' });

        await render(hbs`<Equipment::PanelHeader @resource={{this.resource}} />`);

        assert.dom('h1').hasText('Crane 2');
        assert.dom().includesText('-', 'the serial number falls back to a dash');
        assert.ok(find('img').getAttribute('src').startsWith('data:image/svg+xml'), 'no photo falls back to the configured placeholder');
        assert.dom('.next-content-overlay-panel-cancel-button').doesNotExist('no cancel button without a handler');
    });
});
