import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | work-order/panel-header', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the code, status, priority and assignee', async function (assert) {
        const calls = [];
        this.set('resource', {
            code: 'WO-1',
            subject: 'Replace brake pads',
            status: 'open',
            type: 'preventive_maintenance',
            priority: 'high',
            assignee_name: 'Parts Co',
        });
        this.set('actionButtons', [{ text: 'Refresh', onClick: () => calls.push('refresh') }]);

        await render(hbs`<WorkOrder::PanelHeader @resource={{this.resource}} @actionButtons={{this.actionButtons}} />`);

        assert.dom('h1').hasText('WO-1');
        assert.dom('.status-badge').includesText('Open');
        assert.dom().includesText('Preventive Maintenance');
        assert.dom().includesText('Priority: High');
        assert.dom().includesText('Assignee: Parts Co');

        await click(findAll('button').find((button) => /Refresh/.test(button.textContent)));
        assert.deepEqual(calls, ['refresh']);
    });

    test('without a code it titles on the subject and omits the optional rows', async function (assert) {
        this.set('resource', { subject: 'Replace brake pads', status: 'closed' });

        await render(hbs`<WorkOrder::PanelHeader @resource={{this.resource}} />`);

        assert.dom('h1').hasText('Replace brake pads');
        assert.dom().doesNotIncludeText('Priority:');
        assert.dom().doesNotIncludeText('Assignee:');
    });
});
