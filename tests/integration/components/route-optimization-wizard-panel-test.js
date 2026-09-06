import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | route-optimization-wizard-panel', function (hooks) {
    setupRenderingTest(hooks);

    test('it opens over the sidebar, lists the waypoints and reports its overlay context', async function (assert) {
        const calls = [];
        this.owner.register(
            'service:sidebar',
            class extends Service {
                hide() {
                    calls.push('sidebar.hide');
                }
            }
        );
        this.set('waypoints', [{ address: '1 First St' }, { address: '2 Second Ave' }]);
        this.set('onLoad', (context) => calls.push(['onLoad', typeof context]));
        this.set('controller', { name: 'controller' });

        await render(hbs`<RouteOptimizationWizardPanel @waypoints={{this.waypoints}} @onLoad={{this.onLoad}} @controller={{this.controller}} />`);

        assert.dom().includesText('1 First St');
        assert.dom().includesText('2 Second Ave');
        assert.dom().includesText('Run');
        assert.deepEqual(calls, ['sidebar.hide', ['onLoad', 'object']]);
    });
});
