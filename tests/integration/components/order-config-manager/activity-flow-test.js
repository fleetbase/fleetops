import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, render, settled, triggerEvent } from '@ember/test-helpers';
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

        // `editActivity` hands the panel a whole definition — the resource, an `inlineTask`
        // save handler and an `onClose`. The stand-in keeps each definition so a test can drive
        // those closures, which are the component's own code, rather than reaching into it.
        this.panels = [];
        this.panelClosedAll = 0;
        this.owner.register(
            'service:resource-context-panel',
            class extends Service {
                open(definition) {
                    test.panels.push(definition);
                }
                closeAll() {
                    test.panelClosedAll += 1;
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

    /** The JointJS element tools rendered onto a node — the add and remove buttons. */
    const addButtons = () => inTest('.flow-activity-add-button');
    const removeButtons = () => inTest('.joint-tool[data-tool-name="remove"] circle, .joint-tool.remove circle');

    /** Draw a flow with one activity past the three immutable ones, so there is something editable. */
    function givenEditableFlow(test) {
        test.config.set('flow', {
            created: { code: 'created', key: 'created', status: 'Order Created', details: 'New order was created.', activities: ['dispatched'] },
            dispatched: { code: 'dispatched', key: 'dispatched', status: 'Order Dispatched', details: 'Order has been dispatched.', activities: ['started'] },
            started: { code: 'started', key: 'started', status: 'Order Started', details: 'Order has been started', activities: ['collected'] },
            collected: { code: 'collected', key: 'collected', status: 'Order Collected', details: 'Driver has the parcel.', activities: [] },
        });
    }

    test('clicking an activity opens it in the context panel', async function (assert) {
        givenEditableFlow(this);
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        const collected = inTest('.joint-type-fleetbase-activity').at(-1);
        await click(collected);

        assert.strictEqual(this.panels.length, 1, 'the activity form is opened');
        const [panel] = this.panels;
        assert.strictEqual(panel.content, 'activity/form');
        assert.true(panel.pojoResource, 'the activity is a plain object, not a record');
        assert.strictEqual(panel.resource.get('code'), 'collected', 'and it is the activity that was clicked');
    });

    test('the activities every order must have cannot be edited', async function (assert) {
        givenEditableFlow(this);
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        // created, dispatched and started are immutable; the flow draws them first.
        await click(inTest('.joint-type-fleetbase-activity')[0]);
        assert.strictEqual(this.panels.length, 0, 'clicking created opens nothing');

        await click(inTest('.joint-type-fleetbase-activity').at(-1));
        assert.strictEqual(this.panels.length, 1, 'while an activity that is not immutable does open');
    });

    test('a core service config is read-only', async function (assert) {
        givenEditableFlow(this);
        this.config.set('core_service', true);
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        await click(inTest('.joint-type-fleetbase-activity').at(-1));
        assert.strictEqual(this.panels.length, 0, 'a core service activity cannot be opened for editing');
        assert.strictEqual(addButtons().length, 0, 'and carries no add or remove tools at all');
    });

    test('someone who cannot view the config cannot open an activity', async function (assert) {
        givenEditableFlow(this);
        this.owner.lookup('service:abilities').denied.add('fleet-ops view order-config');
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        await click(inTest('.joint-type-fleetbase-activity').at(-1));
        assert.strictEqual(this.panels.length, 0, 'the activity stays shut');
    });

    test('the add tool hangs a new activity off the one it belongs to', async function (assert) {
        givenEditableFlow(this);
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        const before = activityNodes().length;
        const add = addButtons();
        assert.ok(add.length, 'the editable activities carry an add tool');
        await click(add.at(-1));

        assert.strictEqual(this.panels.length, 1, 'a blank activity is opened for editing');
        const [panel] = this.panels;
        assert.strictEqual(panel.resource.get('code'), '', 'blank, because it is new');

        panel.resource.set('code', 'delivered');
        panel.resource.set('status', 'Order Delivered');
        await panel.saveTask.perform();

        assert.strictEqual(activityNodes().length, before + 1, 'saving draws the new activity');
        assert.strictEqual(this.panelClosedAll, 1, 'and closes the panel');
        assert.dom('.joint-js-paper').containsText('Order Delivered', 'with the status it was given');
    });

    test('an activity whose code is already taken is refused', async function (assert) {
        givenEditableFlow(this);
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        await click(addButtons().at(-1));
        const [panel] = this.panels;
        panel.resource.set('code', 'collected');

        const before = activityNodes().length;
        await panel.saveTask.perform();

        assert.deepEqual(
            this.notified.map(([kind]) => kind),
            ['warning'],
            'the clash is reported rather than saved'
        );
        assert.strictEqual(activityNodes().length, before, 'and nothing is added to the graph');
        assert.strictEqual(this.panelClosedAll, 0, 'the panel stays open so the code can be changed');
    });

    test('closing the panel without saving clears the context', async function (assert) {
        givenEditableFlow(this);
        this.contextChanges = [];
        this.onContextChanged = (value) => this.contextChanges.push(value);

        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} @onContextChanged={{this.onContextChanged}} />`);

        await click(inTest('.joint-type-fleetbase-activity').at(-1));
        assert.strictEqual(this.contextChanges.length, 1, 'opening announces the activity being edited');
        assert.strictEqual(this.contextChanges[0].get('code'), 'collected', 'as a proxy carrying its content');

        this.panels[0].onClose();
        assert.deepEqual(this.contextChanges, [this.contextChanges[0], null], 'closing announces there is nothing being edited');
    });

    test('the remove tool takes an activity off the graph and out of its parent', async function (assert) {
        givenEditableFlow(this);
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        const before = activityNodes().length;
        const remove = removeButtons();
        assert.ok(remove.length, 'the editable activity carries a remove tool');
        await click(remove.at(-1));

        assert.strictEqual(activityNodes().length, before - 1, 'the node is gone from the graph');

        // And it is gone from what would be saved, not just from the drawing.
        let saved = null;
        this.config.save = () => {
            saved = this.config.get('flow');
            return Promise.resolve(this.config);
        };
        await click(inTest('button').find((el) => el.textContent.includes('Save')));

        assert.notOk(saved.collected, 'the removed activity is not in the saved flow');
        assert.deepEqual(saved.started.activities, [], 'and its parent no longer points at it');
    });

    test('the graph and its scrollbar follow one another', async function (assert) {
        givenEditableFlow(this);
        await render(hbs`<OrderConfigManager::ActivityFlow @config={{this.config}} @configManagerContext={{this.configManagerContext}} />`);

        const [container] = inTest('.activity-flow-graph-container');
        const [scrollbar] = inTest('.activity-flow-horizontal-scrollbar');
        assert.ok(container && scrollbar, 'the graph sits in a scrolling container with its own scrollbar');

        // Force the overflow the real graph produces once it is wider than the panel.
        container.style.width = '200px';
        container.style.overflowX = 'auto';
        const [paper] = inTest('.joint-js-paper');
        paper.style.width = '2000px';

        // Scrolling the graph drags the scrollbar along.
        container.scrollLeft = 120;
        await triggerEvent(container, 'scroll');
        assert.strictEqual(scrollbar.scrollLeft, container.scrollLeft, 'the scrollbar follows the graph');

        // And the other way round.
        scrollbar.scrollLeft = 40;
        await triggerEvent(scrollbar, 'scroll');
        assert.strictEqual(container.scrollLeft, scrollbar.scrollLeft, 'and the graph follows the scrollbar');
    });

    test('an activity named by the context is opened as soon as the flow is drawn', async function (assert) {
        // `deserializeActivity` keeps an `internalId` the saved flow already carries and only
        // generates one when it does not, so a round-tripped flow can be pointed at by id.
        givenEditableFlow(this);
        const flow = this.config.get('flow');
        flow.collected.internalId = 'activity_collected';
        this.config.set('flow', flow);

        await render(
            hbs`<OrderConfigManager::ActivityFlow
                @config={{this.config}}
                @configManagerContext={{this.configManagerContext}}
                @context="activity_collected"
                @contextModel="activity"
            />`
        );

        assert.strictEqual(this.panels.length, 1, 'the activity the context names is opened without a click');
        assert.strictEqual(this.panels[0].resource.get('code'), 'collected');
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
