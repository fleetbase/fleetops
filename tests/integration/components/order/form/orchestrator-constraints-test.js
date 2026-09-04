import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import Component from '@glimmer/component';
import { action } from '@ember/object';
import { setComponentTemplate } from '@ember/component';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import { AbilitiesStub, makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | order/form/orchestrator-constraints', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const test = this;
        this.owner.register('service:abilities', AbilitiesStub);

        // The two time-window fields are DateTimeInputs; this stand-in lets a test emit an exact
        // value through `@onUpdate`, which is what the component's setTimeWindow reacts to.
        // `setComponentTemplate` refuses a second call on the same class, so build it per test.
        class DateTimeInputStub extends Component {
            @action fire() {
                this.args.onUpdate(test.emittedValue);
            }
        }
        this.owner.register('component:date-time-input', setComponentTemplate(hbs`<button type="button" data-test-date-time-input {{on "click" this.fire}}></button>`, DateTimeInputStub));
    });

    test('it renders optional orchestrator constraint inputs', async function (assert) {
        this.set('resource', makeRecord('order', { required_skills: [], orchestrator_priority: 50 }));

        await render(hbs`<Order::Form::OrchestratorConstraints @resource={{this.resource}} />`);

        assert.dom().containsText('Orchestrator Constraints');
        assert.dom().containsText('Time Window Start');
        assert.dom().containsText('Time Window End');
        assert.dom().containsText('Required Skills');
        assert.dom().containsText('Orchestrator Priority');
        assert.dom('input[type="number"]').hasValue('50');
    });

    test('an epoch-only value keeps its time and takes its date from the order', async function (assert) {
        this.set('resource', makeRecord('order', { scheduled_at: new Date(2026, 5, 18) }));
        // A UTC epoch date is what DateTimeInput emits when only the time picker was touched;
        // building it in UTC keeps the component's UTC epoch check deterministic across zones.
        const emitted = (this.emittedValue = new Date(Date.UTC(1970, 0, 1, 9, 30)));

        await render(hbs`<Order::Form::OrchestratorConstraints @resource={{this.resource}} />`);
        await click(findAll('[data-test-date-time-input]')[0]);

        const stored = this.resource.time_window_start;
        assert.strictEqual(stored.getFullYear(), 2026);
        assert.strictEqual(stored.getMonth(), 5);
        assert.strictEqual(stored.getDate(), 18);
        assert.strictEqual(stored.getHours(), emitted.getHours(), 'the picked time is preserved');
        assert.strictEqual(stored.getMinutes(), emitted.getMinutes());
        assert.strictEqual(stored.getSeconds(), 0);
    });

    test('the reference date falls back to created_at and then to now', async function (assert) {
        this.emittedValue = new Date(Date.UTC(1970, 0, 1, 7, 15));

        this.set('resource', makeRecord('order', { created_at: '2026-03-04T00:00:00.000Z' }));
        await render(hbs`<Order::Form::OrchestratorConstraints @resource={{this.resource}} />`);
        await click(findAll('[data-test-date-time-input]')[1]);
        const fromCreatedAt = this.resource.time_window_end;
        const created = new Date('2026-03-04T00:00:00.000Z');
        assert.strictEqual(fromCreatedAt.getFullYear(), created.getFullYear(), 'a string created_at is parsed into a date');
        assert.strictEqual(fromCreatedAt.getMonth(), created.getMonth());
        assert.strictEqual(fromCreatedAt.getDate(), created.getDate());

        this.set('resource', makeRecord('order', {}));
        await render(hbs`<Order::Form::OrchestratorConstraints @resource={{this.resource}} />`);
        await click(findAll('[data-test-date-time-input]')[0]);
        const today = new Date();
        assert.strictEqual(this.resource.time_window_start.getFullYear(), today.getFullYear(), 'with no order dates the reference is now');
        assert.strictEqual(this.resource.time_window_start.getDate(), today.getDate());
    });

    test('a value that carries its own date is stored as picked', async function (assert) {
        this.set('resource', makeRecord('order', { scheduled_at: new Date(2026, 5, 18) }));
        this.emittedValue = new Date(2027, 1, 2, 16, 45);

        await render(hbs`<Order::Form::OrchestratorConstraints @resource={{this.resource}} />`);
        await click(findAll('[data-test-date-time-input]')[0]);

        const stored = this.resource.time_window_start;
        assert.strictEqual(stored.getFullYear(), 2027, 'the order date does not override an explicit one');
        assert.strictEqual(stored.getMonth(), 1);
        assert.strictEqual(stored.getDate(), 2);
        assert.strictEqual(stored.getHours(), 16);
    });

    test('clearing a window stores null and an unparseable value is stored as given', async function (assert) {
        this.set('resource', makeRecord('order', { time_window_start: new Date(2026, 5, 18, 9, 0) }));

        this.emittedValue = null;
        await render(hbs`<Order::Form::OrchestratorConstraints @resource={{this.resource}} />`);
        await click(findAll('[data-test-date-time-input]')[0]);
        assert.strictEqual(this.resource.time_window_start, null);

        this.emittedValue = 'not a date';
        await click(findAll('[data-test-date-time-input]')[0]);
        assert.strictEqual(this.resource.time_window_start, 'not a date', 'an unparseable value is passed through untouched');
    });
});
