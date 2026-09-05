import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { getFleetOpsOptionLabel } from '@fleetbase/fleetops-engine/helpers/get-fleet-ops-option-label';

module('Integration | Helper | get-fleet-ops-option-label', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the label of a fleet-ops option value', async function (assert) {
        await render(hbs`{{get-fleet-ops-option-label "driverTypes" "full_time"}}`);
        assert.dom(this.element).hasText('Full-time');
    });

    test('an unknown value renders nothing', async function (assert) {
        await render(hbs`{{get-fleet-ops-option-label "driverTypes" "astronaut"}}`);
        assert.dom(this.element).hasText('');
    });

    test('the exported function returns null for an unknown value', function (assert) {
        assert.strictEqual(getFleetOpsOptionLabel('driverStatuses', 'on_duty'), 'On Duty');
        assert.strictEqual(getFleetOpsOptionLabel('driverStatuses', 'nope'), null);
    });
});
