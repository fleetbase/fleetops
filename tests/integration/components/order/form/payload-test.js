import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | order/form/payload', function (hooks) {
    setupRenderingTest(hooks);

    test('editing a draft entity overrides the modal confirm without saving the entity', async function (assert) {
        assert.expect(8);

        let savedCount = 0;
        let doneCount = 0;
        const entity = {
            isNew: true,
            name: 'Draft box',
            sku: 'BOX-1',
            type: 'entity',
            photo_url: 'https://example.test/box.png',
            save() {
                savedCount++;
            },
        };
        const order = {
            internal_id: 'ORDER-1',
            imported: false,
            payload: {
                entities: [entity],
                waypoints: [],
            },
        };

        class EntityActionsStub extends Service {
            modal = {
                edit(assertedEntity, options) {
                    assert.strictEqual(assertedEntity, entity, 'passes the draft entity to the shared entity modal');
                    assert.strictEqual(typeof options.confirm, 'function', 'overrides the shared entity modal confirm');

                    options.confirm({
                        done() {
                            doneCount++;
                        },
                    });

                    assert.strictEqual(savedCount, 0, 'custom confirm does not save the draft entity');
                    assert.strictEqual(doneCount, 1, 'custom confirm closes the entity modal');
                    assert.strictEqual(order.internal_id, 'ORDER-1', 'draft order details remain intact');
                    assert.strictEqual(order.payload.entities[0], entity, 'draft entity remains attached to the order payload');
                },
            };
        }

        this.owner.register('service:entity-actions', EntityActionsStub);
        this.set('order', order);

        await render(hbs`<Order::Form::Payload @resource={{this.order}} />`);
        const editButton = findAll('button').find((button) => button.textContent.includes('Edit Item'));

        assert.ok(editButton, 'renders the draft entity edit button');
        await click(editButton);

        assert.strictEqual(savedCount, 0, 'clicking edit does not save the entity directly');
    });
});
