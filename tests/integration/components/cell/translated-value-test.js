import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { setupIntl } from 'ember-intl/test-support';

module('Integration | Component | cell/translated-value', function (hooks) {
    setupRenderingTest(hooks);
    setupIntl(hooks, 'en-us');

    test('it renders the localized label for a stable enum value', async function (assert) {
        this.set('row', { type: 'dry_van', connectivity_status: 'never_connected' });
        this.set('column', { valuePath: 'type', translationPrefix: 'trailer.types' });

        await render(hbs`<Cell::TranslatedValue @row={{this.row}} @column={{this.column}} />`);

        assert.dom().hasText('Dry van');
    });

    test('it renders a badge when configured and an empty marker for missing values', async function (assert) {
        this.set('row', { connectivity_status: 'never_connected', type: null });
        this.set('column', { valuePath: 'connectivity_status', translationPrefix: 'trailer.connectivity', badge: true });

        await render(hbs`<Cell::TranslatedValue @row={{this.row}} @column={{this.column}} />`);

        assert.dom('.status-badge').exists();
        assert.dom().includesText('Never connected');

        this.set('column', { valuePath: 'type', translationPrefix: 'trailer.types' });
        await render(hbs`<Cell::TranslatedValue @row={{this.row}} @column={{this.column}} />`);

        assert.dom().hasText('-');
    });

    test('it falls back to the raw value when no translation exists', async function (assert) {
        this.set('row', { type: 'custom_type' });
        this.set('column', { valuePath: 'type', translationPrefix: 'trailer.types' });

        await render(hbs`<Cell::TranslatedValue @row={{this.row}} @column={{this.column}} />`);

        assert.dom().hasText('custom_type');
    });
});
