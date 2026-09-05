import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

module('Integration | Component | admin/navigator-app', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        this.response = { linkUrl: 'https://navigator.example.com/acme' };
        this.owner.register(
            'service:fetch',
            class extends Service {
                get(path) {
                    calls.push(['get', path]);
                    return Promise.resolve(test.response);
                }
            }
        );
    });

    test('it fetches and shows the navigator link', async function (assert) {
        this.set('app', { name: 'Acme' });

        await render(hbs`<Admin::NavigatorApp @app={{this.app}} />`);

        assert.deepEqual(this.calls, [['get', 'fleet-ops/navigator/get-link-app']]);
        assert.dom('.click-to-copy--value').hasText('https://navigator.example.com/acme');
    });

    test('a response with no link leaves the field empty', async function (assert) {
        this.response = {};

        await render(hbs`<Admin::NavigatorApp />`);

        assert.deepEqual(this.calls, [['get', 'fleet-ops/navigator/get-link-app']]);
        assert.dom('.click-to-copy--value').hasText('');
    });
});
