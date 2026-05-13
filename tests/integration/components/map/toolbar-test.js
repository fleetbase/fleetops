import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import Service from '@ember/service';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | map/toolbar', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.fetchCalls = [];
        const testContext = this;

        class FetchStubService extends Service {
            get(url, params) {
                testContext.fetchCalls.push(`${url}:${params.discover.join(',')}`);

                return Promise.resolve({
                    active_live_orders: 5,
                });
            }
        }

        class MapManagerStubService extends Service {
            zoomIn() {}
            zoomOut() {}
        }

        class OrderListOverlayStubService extends Service {
            isOpen = false;
            loaded = false;
            activeOrdersCount = 0;
            toggle() {}
        }

        class ToggleStubService extends Service {
            toggle() {}
        }

        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:map-manager', MapManagerStubService);
        this.owner.register('service:order-list-overlay', OrderListOverlayStubService);
        this.owner.register('service:map-drawer', ToggleStubService);
        this.owner.register('service:global-search', ToggleStubService);
    });

    test('it requests the live active order metric', async function (assert) {
        await render(hbs`<Map::Toolbar />`);

        assert.deepEqual(this.fetchCalls, ['fleet-ops/metrics:active_live_orders']);
        assert.dom('.active-orders-count').hasText('5');
    });

    test('it uses the loaded overlay count while the overlay is open', async function (assert) {
        const overlay = this.owner.lookup('service:order-list-overlay');
        overlay.isOpen = true;
        overlay.loaded = true;
        overlay.activeOrdersCount = 3;

        await render(hbs`<Map::Toolbar />`);

        assert.dom('.active-orders-count').hasText('3');
    });
});
