import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { makeRecord } from 'dummy/tests/helpers/stub-form-inputs';

module('Integration | Component | service-area/form', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        stubFormInputs(this.owner);
        this.owner.register(
            'service:abilities',
            class extends Service {
                can() {
                    return true;
                }
            }
        );
    });

    test('it renders the detail inputs and the geofence triggers bound to the record', async function (assert) {
        this.set(
            'resource',
            makeRecord('service-area', {
                name: 'Central',
                description: 'Desc',
                color: '#ff0000',
                stroke_color: '#0000ff',
                trigger_on_entry: true,
                trigger_on_exit: false,
                dwell_threshold_minutes: 10,
                speed_limit_kmh: 50,
            })
        );

        await render(hbs`<ServiceArea::Form @resource={{this.resource}} />`);

        const values = findAll('input').map((element) => element.value);
        assert.true(values.includes('Central'), 'the name input is bound');
        assert.strictEqual(findAll('input[type="color"]').length, 2, 'border and fill colours');
        assert.dom('.ember-power-select-trigger').exists('the type select renders');
        assert.true(values.includes('10'), 'dwell threshold is bound');
        assert.true(values.includes('50'), 'speed limit is bound');
        assert.dom(this.element).includesText('Trigger on Entry').includesText('Trigger on Exit');

        const toggles = findAll('[role="checkbox"]');
        assert.strictEqual(toggles.length, 2, 'both geofence toggles render');
        assert.dom(toggles[0]).hasAttribute('aria-checked', 'true');
        assert.dom(toggles[1]).hasAttribute('aria-checked', 'false');
        await click(toggles[1]);
        assert.true(this.resource.trigger_on_exit, 'toggling exit writes the flag back to the record');
    });
});
