import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, fillIn, findAll, render, triggerEvent } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import Component from '@glimmer/component';
import { action } from '@ember/object';
import { setComponentTemplate } from '@ember/component';
import { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

function registerEmitter(owner, name, sample) {
    class Emitter extends Component {
        @action emitNothing() {
            this.args.onChange();
        }

        @action emitList() {
            this.args.onChange([sample]);
        }
    }

    owner.register(
        `component:${name}`,
        setComponentTemplate(
            hbs`<div data-test-emitter={{@activity.key}}><button type="button" data-test-emit-nothing {{on "click" this.emitNothing}}></button><button type="button" data-test-emit-list {{on "click" this.emitList}}></button></div>`,
            Emitter
        )
    );
}

module('Integration | Component | activity/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const test = this;
        this.allowed = true;
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return test.allowed;
                }

                cannot() {
                    return !test.allowed;
                }
            }
        );
        registerEmitter(this.owner, 'activity/logic-builder', { type: 'if' });
        registerEmitter(this.owner, 'activity/event-selector', 'order.failed');
    });

    test('it binds the activity and applies every edit', async function (assert) {
        this.set(
            'resource',
            makeRecord('activity', { key: 'created', code: 'created', status: 'Created', details: 'Order created', complete: false, require_pod: true, pod_method: 'scan' })
        );

        await render(hbs`<Activity::Form @resource={{this.resource}} />`);

        assert.deepEqual(
            findAll('input').map((input) => input.value),
            ['created', 'created', 'Created', 'Order created']
        );
        assert.dom('input[disabled]').doesNotExist();
        assert.dom('[role="checkbox"]').exists({ count: 2 });
        assert.dom('select').exists();
        assert.dom('select option[value="scan"]').hasProperty('selected', true);
        assert.dom('[data-test-emitter]').exists({ count: 2 });

        // The code handler derives the status; the key/code slug itself is overwritten by the
        // two-way Input on the same element (DEFECTS #38).
        findAll('input')[1].value = 'in_transit';
        await triggerEvent(findAll('input')[1], 'input');
        assert.strictEqual(this.resource.code, 'in_transit');
        assert.strictEqual(this.resource.status, 'In Transit');
        findAll('input')[0].value = 'Order Started';
        await triggerEvent(findAll('input')[0], 'input');
        assert.strictEqual(this.resource.key, 'Order Started', 'DEFECTS #38: the raw value wins over the underscored one');

        await click(findAll('[role="checkbox"]')[0]);
        assert.true(this.resource.complete);

        await fillIn('select', 'photo');
        assert.strictEqual(this.resource.pod_method, 'photo');

        await click(findAll('[data-test-emit-list]')[0]);
        assert.deepEqual(this.resource.logic, [{ type: 'if' }]);
        await click(findAll('[data-test-emit-nothing]')[0]);
        assert.deepEqual(this.resource.logic, []);
        await click(findAll('[data-test-emit-list]')[1]);
        assert.deepEqual(this.resource.events, ['order.failed']);
        await click(findAll('[data-test-emit-nothing]')[1]);
        assert.deepEqual(this.resource.events, []);

        await click(findAll('[role="checkbox"]')[1]);
        assert.false(this.resource.require_pod);
        assert.dom('select').doesNotExist();
    });

    test('without permission every control is disabled and the builders are hidden', async function (assert) {
        this.allowed = false;
        this.set('resource', makeRecord('activity', { key: 'created', require_pod: true }));

        await render(hbs`<Activity::Form @resource={{this.resource}} />`);

        assert.strictEqual(findAll('input:not([disabled])').length, 0);
        assert.dom('select').isDisabled();
        assert.dom('[data-test-emitter]').doesNotExist();
    });
});
