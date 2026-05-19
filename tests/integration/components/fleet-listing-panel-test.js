import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

module('Integration | Component | fleet-listing-panel', function (hooks) {
    setupRenderingTest(hooks);

    test('it renders subfleet vehicles and direct-only counts', async function (assert) {
        this.set('fleet', {
            name: 'EastLink',
            vehicles_online_count: 1,
            vehicles_count: 1,
            vehicles: [
                {
                    displayName: '2025 Nissan NV200',
                    online: true,
                },
            ],
            subfleets: [
                {
                    name: 'Changi Cargo',
                    vehicles_online_count: 3,
                    vehicles_count: 3,
                    vehicles: [
                        {
                            displayName: 'CGO-05',
                            online: true,
                        },
                        {
                            displayName: 'CGO-16',
                            online: true,
                        },
                        {
                            displayName: 'CGO-17',
                            online: true,
                        },
                    ],
                    subfleets: [],
                },
            ],
        });

        await render(hbs`<FleetListingPanel @fleet={{this.fleet}} @depth={{1}} />`);

        assert.dom(this.element).includesText('EastLink');
        assert.dom(this.element).includesText('1/1');
        assert.dom(this.element).includesText('2025 Nissan NV200');
        assert.dom(this.element).includesText('Changi Cargo');
        assert.dom(this.element).includesText('3/3');
        assert.dom(this.element).includesText('CGO-05');
        assert.dom(this.element).includesText('CGO-16');
        assert.dom(this.element).includesText('CGO-17');
    });
});
