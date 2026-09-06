import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | cell/driver-name', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the row driver with the vehicle assigned on the row', async function (assert) {
        this.set('row', {
            driver: { name: 'Ada Driver', photo_url: '/ada.png', online: true, vehicle: { id: 'v1' }, vehicle_name: 'Driver Van' },
            vehicle_assigned: { display_name: 'Row Truck' },
        });

        await render(hbs`<Cell::DriverName @row={{this.row}} @column={{(hash)}} />`);

        assert.dom('a').includesText('Ada Driver');
        assert.dom('img').hasAttribute('src', '/ada.png').hasAttribute('alt', 'Ada Driver');
        assert.dom(this.element).includesText('Row Truck');
        assert.dom(this.element).doesNotIncludeText('Driver Van', 'the row assignment wins over the driver vehicle');
        assert.dom('svg[data-icon="circle"]').hasClass('text-green-500');
    });

    test('it treats the row itself as the driver and falls back to the driver vehicle', async function (assert) {
        this.set('row', { name: 'Solo Driver', online: false, vehicle: { id: 'v2' }, vehicle_name: 'Solo Van' });

        await render(hbs`<Cell::DriverName @row={{this.row}} @column={{(hash)}} />`);

        assert.dom('a').includesText('Solo Driver');
        assert.dom(this.element).includesText('Solo Van');
        assert.dom('svg[data-icon="circle"]').hasClass('text-yellow-200');
    });

    test('it resolves the driver through the column model path', async function (assert) {
        this.set('row', { assignment: { driver: { name: 'Nested Driver' } } });

        await render(hbs`<Cell::DriverName @row={{this.row}} @column={{hash modelPath="assignment.driver"}} />`);

        assert.dom('a').includesText('Nested Driver');
        assert.dom(this.element).doesNotIncludeText('No driver assigned');
    });

    test('it explains when no driver is assigned', async function (assert) {
        this.set('row', { assignment: {} });

        await render(hbs`<Cell::DriverName @row={{this.row}} @column={{hash modelPath="assignment.driver"}} />`);

        assert.dom(this.element).hasText('No driver assigned');
        assert.dom('a').doesNotExist();
    });

    test('clicking the name delegates to every configured handler with the driver and row', async function (assert) {
        const calls = [];
        const driver = { name: 'Click Driver' };
        this.set('row', { driver });
        this.set('onClick', (...args) => calls.push(['onClick', ...args]));
        this.set('column', { action: (...args) => calls.push(['action', ...args]), onClick: (...args) => calls.push(['column.onClick', ...args]) });

        await render(hbs`<Cell::DriverName @row={{this.row}} @column={{this.column}} @onClick={{this.onClick}} />`);
        await click('a');

        assert.deepEqual(
            calls.map(([name, first, second]) => [name, first === driver, second === this.row]),
            [
                ['onClick', true, true],
                ['action', true, true],
                ['column.onClick', true, true],
            ]
        );
    });

    test('clicking without any handler is a no-op', async function (assert) {
        this.set('row', { driver: { name: 'Quiet Driver' } });

        await render(hbs`<Cell::DriverName @row={{this.row}} @column={{(hash)}} />`);
        await click('a');

        assert.dom('a').includesText('Quiet Driver');
    });
});
