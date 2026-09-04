import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | modals/attach-device', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
    });

    test('it renders the unattached-device select and writes the choice onto the options', async function (assert) {
        this.set('options', { title: 'Attach Device', selectedDevice: null });

        await render(hbs`<Modals::AttachDevice @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom().includesText('Attach Device');
        assert.dom('label').hasText('Select Device');
        assert.dom('[data-test-model-select="device"]').hasText('Select Device');

        await click('[data-test-model-select="device"]');
        assert.strictEqual(this.options.selectedDevice.id, 'picked_1');

        await click('[data-test-model-select-clear="device"]');
        assert.strictEqual(this.options.selectedDevice, null);
    });
});
