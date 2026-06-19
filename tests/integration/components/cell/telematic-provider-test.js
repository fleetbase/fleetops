import { click, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';

module('Integration | Component | cell/telematic-provider', function (hooks) {
    setupRenderingTest(hooks);

    test('it constrains description width and delegates clicks', async function (assert) {
        assert.expect(3);

        this.set('row', {
            telematic_uuid: 'telematic_1',
            telematic_name: 'AFAQY',
            provider: 'afaqy',
            provider_descriptor: {
                description: 'Vehicle telemetry and location provider',
            },
        });
        this.set('column', {
            action: (telematic) => {
                assert.strictEqual(telematic.id, 'telematic_1', 'click receives the telematic resource');
            },
        });

        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);

        assert.dom('.max-w-\\[225px\\]').exists();
        assert.dom(this.element).includesText('Vehicle telemetry and location provider');

        await click('button');
    });

    test('it renders compact image and name without description', async function (assert) {
        assert.expect(8);

        this.set('row', {
            telematic_uuid: 'telematic_1',
            telematic_name: 'AFAQY',
            provider: 'afaqy',
            provider_descriptor: {
                icon: '/engines-dist/images/telematics/providers/afaqy.webp',
                description: 'Vehicle telemetry and location provider',
            },
        });
        this.set('column', {
            compact: true,
            action: (telematic) => {
                assert.strictEqual(telematic.id, 'telematic_1', 'compact click receives the telematic resource');
            },
        });

        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);

        assert.dom('[data-test-telematic-provider-compact]').exists();
        assert.dom('[data-test-telematic-provider-compact] img').hasClass('h-5');
        assert.dom('[data-test-telematic-provider-compact] img').hasClass('w-5');
        assert.dom('[data-test-telematic-provider-compact] .text-sm').exists();
        assert.dom('[data-test-telematic-provider-compact] .font-semibold').doesNotExist();
        assert.dom(this.element).includesText('AFAQY');
        assert.dom(this.element).doesNotIncludeText('Vehicle telemetry and location provider');

        await click('button');
    });

    test('it renders empty text when configured resourcePath resolves empty', async function (assert) {
        this.set('row', { message: 'event without provider' });
        this.set('column', {
            resourcePath: () => null,
            emptyText: 'No provider',
        });

        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);

        assert.dom('[data-test-telematic-provider-empty-text]').hasText('No provider');
        assert.dom('button').doesNotExist();
    });
});
