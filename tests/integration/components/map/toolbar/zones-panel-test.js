import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render, triggerEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | map/toolbar/zones-panel', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const record =
            (name) =>
            (...args) =>
                calls.push([name, ...(typeof args[0]?.name === 'string' ? [args[0].name] : [])]);
        this.areas = [
            { name: 'North', hidden: true },
            { name: 'South', hidden: false },
        ];
        this.owner.register(
            'service:geofence',
            class extends Service {
                createServiceArea = record('createServiceArea');
                showAllServiceAreas = record('showAllServiceAreas');
                hideAllServiceAreas = record('hideAllServiceAreas');
                focusServiceArea = record('focusServiceArea');
                blurServiceArea = record('blurServiceArea');
                createZone = record('createZone');
                editServiceArea = record('editServiceArea');
            }
        );
        const areas = this.areas;
        this.owner.register(
            'service:service-area-actions',
            class extends Service {
                serviceAreas = areas;
                modal = { edit: record('modal.edit') };
                delete = record('delete');
            }
        );
        this.owner.register(
            'service:leaflet-layer-visibility-manager',
            class extends Service {
                isModelLayerHidden(model) {
                    return model.hidden;
                }
            }
        );
        this.closed = 0;
        this.set('dd', { uniqueId: 'dd', isOpen: true, disabled: false, actions: { close: () => this.closed++ }, Trigger: null, Content: null });
    });

    test('it offers the service-area actions and one hover menu per area', async function (assert) {
        await render(hbs`<Map::Toolbar::ZonesPanel @dd={{this.dd}} />`);

        assert.dom().includesText('Service Areas');
        assert.dom().includesText('North');
        assert.dom().includesText('South');

        const [create, show, hide] = findAll('.next-dd-item');
        await click(create);
        await click(show);
        await click(hide);
        assert.deepEqual(this.calls, [['createServiceArea'], ['showAllServiceAreas'], ['hideAllServiceAreas']]);
        assert.strictEqual(this.closed, 3, 'each action closes the toolbar menu');

        const triggers = findAll('.ember-basic-dropdown-trigger');
        assert.strictEqual(triggers.length, 2);
        await triggerEvent(triggers[0], 'mouseenter');
        assert.dom().includesText('North Actions');
        assert.dom().includesText('Focus: North', 'a hidden area offers focus');
        assert.dom('.ember-basic-dropdown-content .next-dd-item').exists({ count: 5 });
        assert.ok(findAll('.ember-basic-dropdown-content')[0].getAttribute('style').includes('calc('), 'the submenu is placed beside its trigger');
        // Each action closes the hover menu, so reopen it before the next item.
        for (const label of [/Focus: North/, /Create Zone/i, /Edit: North/, /boundaries/i, /Delete: North/]) {
            if (!findAll('.ember-basic-dropdown-content .next-dd-item').length) {
                await triggerEvent(triggers[0], 'mouseenter');
            }
            await click(findAll('.ember-basic-dropdown-content .next-dd-item').find((el) => label.test(el.textContent)));
        }
        assert.deepEqual(this.calls.slice(3), [
            ['focusServiceArea', 'North'],
            ['createZone', 'North'],
            ['modal.edit', 'North'],
            ['editServiceArea', 'North'],
            ['delete', 'North'],
        ]);

        await triggerEvent(triggers[1], 'mouseenter');
        assert.dom().includesText('Hide: South', 'a visible area offers hide');
        await click(findAll('.ember-basic-dropdown-content .next-dd-item').find((el) => /Hide: South/.test(el.textContent)));
        assert.deepEqual(this.calls.at(-1), ['blurServiceArea', 'South']);
    });

    test('without loaded service areas the list is empty', async function (assert) {
        this.owner.lookup('service:service-area-actions').serviceAreas = null;
        await render(hbs`<Map::Toolbar::ZonesPanel @dd={{this.dd}} />`);
        assert.dom('.ember-basic-dropdown-trigger').doesNotExist();
        assert.dom('.next-dd-item').exists({ count: 3 });
    });
});
