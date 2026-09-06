import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | route-optimization-engine-select-button', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register(
            'service:route-optimization',
            class extends Service {
                availableServices = [
                    { key: 'osrm', name: 'OSRM' },
                    { key: 'google', name: 'Google Routes' },
                ];
            }
        );
    });

    test('it lists the registered engines and hands the picked key to @onClick', async function (assert) {
        const picked = [];
        this.set('onClick', (key) => picked.push(key));

        await render(hbs`<RouteOptimizationEngineSelectButton @onClick={{this.onClick}} />`);
        assert.dom(this.element).includesText('Optimize Route');

        await click('.ember-basic-dropdown-trigger');
        assert.deepEqual(
            findAll('.next-dd-item').map((element) => element.textContent.trim()),
            ['OSRM', 'Google Routes']
        );

        await click(findAll('.next-dd-item')[1]);
        assert.deepEqual(picked, ['google']);
    });

    test('picking an engine without an @onClick is a no-op', async function (assert) {
        await render(hbs`<RouteOptimizationEngineSelectButton />`);
        await click('.ember-basic-dropdown-trigger');
        await click('.next-dd-item');
        assert.dom(this.element).includesText('Optimize Route');
    });
});
