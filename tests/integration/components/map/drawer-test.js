import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | map/drawer', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        registerTemplateOnly(this.owner, 'drawer', hbs`<div data-test-drawer data-test-minimized={{@isMinimized}} {{did-insert (fn @onLoad (hash id="drawer-api"))}}>{{yield}}</div>`);
        registerTemplateOnly(this.owner, 'tab-navigation', hbs`<ul data-test-tabs>{{#each @tabs as |tab|}}<li data-test-tab={{tab.component}}>{{tab.title}}</li>{{/each}}</ul>`);
    });

    test('it lists the built-in tabs plus any registered ones and hands the drawer to the map drawer service', async function (assert) {
        const mapDrawer = this.owner.lookup('service:map-drawer');

        await render(hbs`<Map::Drawer @isOpen={{true}} />`);

        assert.deepEqual(
            findAll('[data-test-tab]').map((li) => [li.textContent.trim(), li.getAttribute('data-test-tab')]),
            [
                ['Vehicles', 'map/drawer/vehicle-listing'],
                ['Drivers', 'map/drawer/driver-listing'],
                ['Places', 'map/drawer/place-listing'],
                ['Positions', 'map/drawer/position-listing'],
                ['Geofences', 'map/drawer/geofence-event-listing'],
                ['Events', 'map/drawer/device-event-listing'],
            ]
        );
        assert.deepEqual(mapDrawer.drawer, { id: 'drawer-api' }, 'the drawer api reaches the service');
        assert.ok(mapDrawer.drawerComponent, 'so does the component');
    });

    test('registered drawer tabs are appended and a missing registry is tolerated', async function (assert) {
        const universe = this.owner.lookup('service:universe');
        const extra = universe._createMenuItem('Custom', null, { icon: 'star', component: 'custom/tab' });
        this.owner.register(
            'service:universe/menu-service',
            class extends Service {
                getMenuItems(registry) {
                    return registry === 'fleet-ops:component:map:drawer' ? [extra] : undefined;
                }
            }
        );

        await render(hbs`<Map::Drawer @isOpen={{true}} />`);
        assert.dom('[data-test-tab]').exists({ count: 7 });
        assert.dom('[data-test-tab="custom/tab"]').hasText('Custom');

        this.owner.lookup('service:universe/menu-service').getMenuItems = () => undefined;
        await render(hbs`<Map::Drawer @isOpen={{true}} />`);
        assert.dom('[data-test-tab]').exists({ count: 6 }, 'a registry that yields nothing adds nothing');
    });
});
