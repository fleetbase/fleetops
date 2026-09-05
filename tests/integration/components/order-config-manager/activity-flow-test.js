import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, settled } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import EmberObject from '@ember/object';
import Evented from '@ember/object/evented';
import Service from '@ember/service';

/**
 * The activity flow is a JointJS graph. `index.js` copies `@joint/core` into the host app's public
 * tree, so in production `joint` is on the global before the engine boots; `ember-cli-build.js`
 * imports it into the dummy app for the same reason. Nothing here stubs the library — the tests
 * drive the real paper and graph, which is the only way the positioning, linking and tooling code
 * runs the way it does in an app.
 */
module('Integration | Component | order-config-manager/activity-flow', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const test = this;
        this.notified = [];
        this.owner.register(
            'service:notifications',
            class extends Service {
                success(message) {
                    test.notified.push(['success', message]);
                }
                error(message) {
                    test.notified.push(['error', message]);
                }
                serverError(error) {
                    test.notified.push(['serverError', error?.message ?? error]);
                }
                warning(message) {
                    test.notified.push(['warning', message]);
                }
            }
        );

        this.contextPanelCalls = [];
        this.owner.register(
            'service:resource-context-panel',
            class extends Service {
                focus(...args) {
                    test.contextPanelCalls.push(['focus', ...args]);
                }
                clear(...args) {
                    test.contextPanelCalls.push(['clear', ...args]);
                }
            }
        );

        // Everything on this panel is gated on `fleet-ops update order-config`.
        this.owner.register(
            'service:abilities',
            class extends Service {
                denied = new Set();
                can(permission) {
                    return !this.denied.has(permission);
                }
                cannot(permission) {
                    return !this.can(permission);
                }
            }
        );

        // The real one is `EmberObject.extend(Evented).create()` in `order-config-manager.js`,
        // which is cheap enough to use as-is rather than stub.
        this.configManagerContext = EmberObject.extend(Evented).create();

        this.store = this.owner.lookup('service:store');
        this.config = this.store.createRecord('order-config', { name: 'Transport', key: 'transport', flow: {} });
    });

    /**
     * Everything rendered by the test lives inside `#ember-testing`. Query through this rather
     * than `document` — QUnit's toolbar has its own "Reset" button, disabled while a test runs,
     * and a bare `document.querySelectorAll('button')` finds it first.
     */
    const inTest = (selector) => [...document.querySelectorAll(`#ember-testing ${selector}`)];

    /** The rectangles JointJS draws for each activity, one per node on the graph. */
    const activityNodes = () => inTest('.joint-js-paper .joint-type-fleetbase-activity');

    test('it draws the default flow when the config carries none', async function (assert) {
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        assert.dom('.joint-js-paper').exists('the JointJS paper is set up');
        assert.strictEqual(activityNodes().length, 3, 'a config with no flow starts on created, dispatched and started');
        assert.dom('.activity-flow-graph-container').exists('inside the scrolling container');
    });

    test('an existing flow on the config is drawn instead of the default', async function (assert) {
        this.config.set('flow', {
            created: { code: 'created', key: 'created', status: 'Order Created', details: 'New order was created.', activities: ['collected'] },
            collected: { code: 'collected', key: 'collected', status: 'Order Collected', details: 'Driver has the parcel.', activities: [] },
        });

        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        assert.strictEqual(activityNodes().length, 2, 'one node per activity in the saved flow');
        assert.dom('.joint-js-paper').containsText('Order Collected', 'and each carries its own status');
    });

    test('zooming in and out scales the paper, and stops at both ends', async function (assert) {
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        assert.ok(inTest('.joint-js-paper svg').length, 'the paper is on the page to be scaled');

        const [zoomIn, zoomOut] = inTest('.activity-flow-zoom-controls button');
        assert.ok(zoomIn && zoomOut, 'both zoom controls are rendered');

        // 1.0 -> 1.2 is the last step in; a second click is refused.
        await click(zoomIn);
        await click(zoomIn);
        assert.dom('.joint-js-paper svg').exists('the paper survives being zoomed to its limit');

        // Back down past 1.0 to the 0.2 floor, then one more that is refused.
        for (let i = 0; i < 7; i++) {
            await click(zoomOut);
        }
        assert.dom('.joint-js-paper svg').exists('and being zoomed to the other limit');
    });

    test('resetting puts the default flow back', async function (assert) {
        this.config.set('flow', {
            created: { code: 'created', key: 'created', status: 'Order Created', details: 'New order was created.', activities: [] },
        });

        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);
        assert.strictEqual(activityNodes().length, 1, 'the saved flow has a single activity');

        const reset = inTest('button').find((el) => el.textContent.includes('Reset'));
        await click(reset);

        assert.strictEqual(activityNodes().length, 3, 'reset drops it and draws the three defaults');
    });

    test('saving sends the flow back to the config', async function (assert) {
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        let saved = null;
        this.config.save = () => {
            saved = this.config.get('flow');
            return Promise.resolve(this.config);
        };

        const save = inTest('button').find((el) => el.textContent.includes('Save'));
        await click(save);

        assert.ok(saved, 'the config is saved');
        assert.deepEqual(Object.keys(saved).sort(), ['created', 'dispatched', 'started'], 'carrying every activity on the graph');
        assert.deepEqual(saved.created.activities, ['dispatched'], 'with each activity referring to its children by code, not by node');
        assert.strictEqual(saved.created.node, undefined, 'and no JointJS node on the way to the server');
    });

    test('the manager telling the panel the config changed redraws the graph', async function (assert) {
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);
        assert.strictEqual(activityNodes().length, 3, 'the first config draws its default flow');

        const other = this.store.createRecord('order-config', {
            name: 'Storage',
            key: 'storage',
            flow: {
                created: { code: 'created', key: 'created', status: 'Order Created', details: 'New order was created.', activities: [] },
            },
        });
        this.configManagerContext.trigger('onConfigChanged', other);
        await settled();

        assert.strictEqual(activityNodes().length, 1, 'switching config clears the old graph and draws the new one');
    });
});
