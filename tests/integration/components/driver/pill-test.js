import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | driver/pill', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders the driver with a phone subtitle and online indicator', async function (assert) {
        this.set('driver', { name: 'Ada Driver', phone: '+6512345678', online: true });

        await render(hbs`<Driver::Pill @driver={{this.driver}} />`);
        assert.dom('.fleetbase-pill').includesText('Ada Driver').includesText('+6512345678');
        assert.dom('svg[data-icon="circle"]').hasClass('text-green-500');

        this.set('driver', { name: 'Bo Driver', online: false });
        await render(hbs`<Driver::Pill @resource={{this.driver}} />`);
        assert.dom('.fleetbase-pill').includesText('Bo Driver').includesText('No phone');
        assert.dom('svg[data-icon="circle"]').hasClass('text-yellow-200');
    });

    test('without a driver it shows the fallback title and no indicator', async function (assert) {
        await render(hbs`<Driver::Pill />`);
        assert.dom('.fleetbase-pill').includesText('No driver').includesText('-');
        assert.dom('svg[data-icon="circle"]').doesNotExist();

        await render(hbs`<Driver::Pill @titleFallback="Unassigned" />`);
        assert.dom('.fleetbase-pill').includesText('Unassigned');
    });
});
