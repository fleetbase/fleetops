import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import stubFormInputs from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | modals/bulk-assign-driver', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
    });

    test('it lists the selected orders, picks a driver and toggles the notification', async function (assert) {
        const calls = [];
        this.set('options', {
            title: 'Assign Driver',
            modelName: 'order',
            verb: 'assign',
            count: 2,
            modelNamePath: 'tracking',
            selected: [
                { id: 'o1', public_id: 'order_1', tracking: 'FLB-1' },
                { id: 'o2', public_id: 'order_2', tracking: 'FLB-2' },
            ],
            remove: (order) => calls.push(['remove', order.public_id]),
            driverAssigned: null,
            driversQuery: {},
            selectDriver: (driver) => calls.push(['selectDriver', driver?.id ?? null]),
            notifyDriver: false,
            toggleNotifyDriver: (checked) => calls.push(['toggleNotifyDriver', checked]),
        });

        await render(hbs`<Modals::BulkAssignDriver @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom().includesText('Are you sure you want to assign these orders?');
        assert.dom().includesText('You have selected 2 orders for assign.');
        assert.dom('li[data-public-id="order_1"]').includesText('FLB-1');
        assert.dom('li[data-public-id="order_2"]').includesText('FLB-2');
        assert.dom('label').hasText('Select Driver');
        assert.dom('[data-test-model-select="driver"]').hasText('Select Driver');
        assert.dom('.fleetbase-checkbox').isNotChecked();

        await click('[data-test-model-select="driver"]');
        await click('[data-test-model-select-clear="driver"]');
        await click('.fleetbase-checkbox');
        await click('li[data-public-id="order_2"] a');

        assert.deepEqual(calls, [
            ['selectDriver', 'picked_1'],
            ['selectDriver', null],
            ['toggleNotifyDriver', true],
            ['remove', 'order_2'],
        ]);
    });
});
