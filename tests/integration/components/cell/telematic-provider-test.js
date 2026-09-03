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

    test('the telematic resolves from a function path, a relation, or is synthesised from row fields', async function (assert) {
        this.set('column', { resourcePath: (row) => row.nested });
        this.set('row', { nested: { name: 'Function Provider', provider_descriptor: { description: 'via function' } } });
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        assert.dom(this.element).includesText('Function Provider').includesText('via function');

        this.set('column', { resourcePath: () => undefined });
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-telematic-provider-empty-text]').hasText('-');

        this.set('column', { resourcePath: 'nested' });
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        assert.dom(this.element).includesText('Function Provider');

        this.set('column', { resourcePath: 'missing', emptyText: 'No provider' });
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        assert.dom('[data-test-telematic-provider-empty-text]').hasText('No provider');

        this.set('column', {});
        this.set('row', { telematic: { name: 'Relation Provider', provider: 'geotab' } });
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        assert.dom(this.element).includesText('Relation Provider').includesText('geotab');

        this.set('row', { telematic_uuid: 't_1', telematic_name: 'Synth Provider', provider: 'samsara', provider_descriptor: { icon: '/samsara.png' } });
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        assert.dom(this.element).includesText('Synth Provider').includesText('samsara');

        this.set('row', { provider: 'plain', provider_descriptor: { label: 'Descriptor Label' } });
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        assert.dom(this.element).includesText('Descriptor Label').includesText('plain');

        this.set('row', { telematic_name: 'Name Only' });
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        assert.dom(this.element).includesText('Name Only');
    });

    test('clicking delegates the telematic to the cell handler and both column handlers', async function (assert) {
        const calls = [];
        const telematic = { name: 'Clickable' };
        this.set('row', { telematic });
        this.set('column', { action: (resource) => calls.push(['action', resource]), onClick: (resource) => calls.push(['column.onClick', resource]) });
        this.set('onClick', (resource) => calls.push(['onClick', resource]));

        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} @onClick={{this.onClick}} />`);
        await click('button');

        assert.deepEqual(
            calls.map(([name, resource]) => [name, resource === telematic]),
            [
                ['onClick', true],
                ['action', true],
                ['column.onClick', true],
            ]
        );

        this.set('column', {});
        await render(hbs`<Cell::TelematicProvider @row={{this.row}} @column={{this.column}} />`);
        await click('button');
        assert.strictEqual(calls.length, 3, 'no handlers, no calls');
    });
});
