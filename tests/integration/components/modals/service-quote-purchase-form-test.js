import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { find, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/service-quote-purchase-form', function (hooks) {
    setupRenderingTest(hooks);

    test('it hands the checkout mount point to the options once inserted', async function (assert) {
        const inserted = [];
        this.set('options', { title: 'Purchase Quote', checkoutElementInserted: (element) => inserted.push(element) });

        await render(hbs`<Modals::ServiceQuotePurchaseForm @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom().includesText('Purchase Quote');
        assert.dom('#checkout').exists();
        assert.strictEqual(inserted.length, 1);
        assert.strictEqual(inserted[0], find('#checkout'));
    });
});
