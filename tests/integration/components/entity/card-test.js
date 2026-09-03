import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, find, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | entity/card', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        this.owner.register(
            'service:entity-actions',
            class extends Service {
                modal = { edit: (resource) => calls.push(['edit', resource]) };
                viewLabel = (resource) => calls.push(['viewLabel', resource]);
                delete = (resource) => calls.push(['delete', resource]);
            }
        );
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return true;
                }

                cannot() {
                    return false;
                }
            }
        );
    });

    test('it renders the entity identity, image and footer actions', async function (assert) {
        this.set('resource', { name: 'Parcel', tracking: 'TRK-1', photo_url: '/parcel.png', updatedAt: '2026-01-02' });

        await render(hbs`<Entity::Card @resource={{this.resource}} />`);

        assert.dom(this.element).includesText('Parcel').includesText('TRK-1').includesText('Last Modified: 2026-01-02');
        assert.dom('img.card-img-sm').exists();

        await click(find('svg[data-icon="pencil"]').closest('button'));
        await click(find('svg[data-icon="barcode"]').closest('button'));
        await click(find('svg[data-icon="trash"]').closest('button'));
        assert.deepEqual(
            this.calls.map(([name, resource]) => [name, resource === this.resource]),
            [
                ['edit', true],
                ['viewLabel', true],
                ['delete', true],
            ]
        );
    });

    test('it falls back to the public id and sku or internal id, and yields the named blocks', async function (assert) {
        this.set('resource', { public_id: 'entity_1', sku: 'SKU-1' });

        await render(hbs`
            <Entity::Card @resource={{this.resource}}>
                <:header>header block</:header>
                <:body>body block</:body>
                <:footer>footer block</:footer>
            </Entity::Card>
        `);
        assert.dom(this.element).includesText('entity_1').includesText('SKU-1').includesText('header block').includesText('body block').includesText('footer block');

        this.set('resource', { public_id: 'entity_2', internal_id: 'INT-2' });
        await render(hbs`<Entity::Card @resource={{this.resource}} />`);
        assert.dom(this.element).includesText('INT-2');
    });
});
