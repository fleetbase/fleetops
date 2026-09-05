import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | modals/confirm-service-quote-purchase', function (hooks) {
    setupRenderingTest(hooks);

    test('it shows the purchase progress message inside the modal', async function (assert) {
        this.set('options', { title: 'Purchasing Quote', loadingMessage: 'Purchasing service quote...' });

        await render(hbs`<Modals::ConfirmServiceQuotePurchase @modalIsOpened={{true}} @options={{this.options}} />`);

        assert.dom().includesText('Purchasing Quote');
        assert.dom('.fleetbase-loader').exists();
        assert.dom('.loading-message').hasText('Purchasing service quote...');
        assert.dom('.loading-message').hasClass('ml-2');
    });
});
