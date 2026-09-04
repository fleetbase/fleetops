import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { click, findAll, render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';
import stubFormInputs, { AbilitiesStub } from 'dummy/tests/helpers/stub-form-inputs';
import registerTemplateOnly from 'dummy/tests/helpers/register-template-only';

module('Integration | Component | map/drawer/device-event-listing', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        const calls = (this.calls = []);
        const test = this;
        stubFormInputs(this.owner);
        this.owner.register('service:abilities', AbilitiesStub);
        // The real picker is a flatpickr range; this stand-in reports a range the way it does.
        registerTemplateOnly(
            this.owner,
            'date-picker',
            hbs`<button type="button" data-test-date-picker={{@placeholder}} {{on "click" (fn @onSelect (hash formattedDate=(array "2026-08-01" "2026-08-07")))}}></button><button
                type="button"
                data-test-date-picker-partial
                {{on "click" (fn @onSelect (hash formattedDate=(array "2026-08-01")))}}
            ></button>`
        );

        this.queryFails = false;
        this.events = [
            { id: 'device_event_1', event_type: 'ignition_on', device: { displayName: 'Tracker A' }, provider: 'flespi', severity: 'info', code: 'IGN', createdAt: '1 Sep 2026' },
            { id: 'device_event_2', event_type: 'harsh_braking', device: { displayName: 'Tracker B' }, provider: 'samsara', severity: 'warning', code: 'HB', createdAt: '2 Sep 2026' },
        ];
        this.owner.register(
            'service:store',
            class extends Service {
                query(modelName, params) {
                    calls.push(['query', modelName, params]);
                    if (test.queryFails) {
                        return Promise.reject(new Error('device events unavailable'));
                    }
                    return Promise.resolve(test.events);
                }
            }
        );
        this.owner.register(
            'service:notifications',
            class extends Service {
                serverError(error) {
                    calls.push(['serverError', error.message]);
                }
            }
        );
        this.owner.register(
            'service:device-event-actions',
            class extends Service {
                panel = { view: (event) => calls.push(['deviceEvent.view', event.id]) };
            }
        );
        this.owner.register(
            'service:device-actions',
            class extends Service {
                panel = { view: (device) => calls.push(['device.view', device?.displayName]) };
            }
        );
    });

    test('it loads the week of events and lists them', async function (assert) {
        await render(hbs`<Map::Drawer::DeviceEventListing />`);

        const [[, modelName, params]] = this.calls;
        assert.strictEqual(modelName, 'device-event');
        assert.strictEqual(params.limit, 900);
        assert.strictEqual(params.sort, 'created_at');
        assert.ok(/^\d{4}-\d{2}-\d{2},\d{4}-\d{2}-\d{2}$/.test(params.created_at), 'the default filter is the current week as a range');
        assert.notOk('device' in params, 'no device filter until one is picked');
        assert.notOk('telematic' in params, 'no telematic filter until one is picked');

        assert.dom('tbody tr').exists({ count: 2 });
        assert.dom().includesText('ignition_on');
        assert.dom().includesText('Tracker A');
        assert.dom().includesText('flespi');
        assert.dom().includesText('warning');
        assert.dom().includesText('HB');
        assert.dom('[data-test-model-select="telematic"]').hasText('Filter by Telematic');
        assert.dom('[data-test-model-select="device"]').hasText('Filter by Device');
        assert.dom('[data-test-date-picker="Select date range"]').exists();
    });

    test('the filters reload the events and the row actions open the panels', async function (assert) {
        await render(hbs`<Map::Drawer::DeviceEventListing />`);
        this.calls.length = 0;

        await click('[data-test-model-select="telematic"]');
        assert.strictEqual(this.calls.at(-1)[2].telematic, 'picked_1');

        await click('[data-test-model-select="device"]');
        assert.strictEqual(this.calls.at(-1)[2].device, 'picked_1');

        await click('[data-test-date-picker]');
        assert.strictEqual(this.calls.at(-1)[2].created_at, '2026-08-01,2026-08-07');

        this.calls.length = 0;
        await click('[data-test-date-picker-partial]');
        assert.deepEqual(this.calls, [], 'a half-finished range does not reload');

        const cells = (row) => findAll('tbody tr')[row].querySelectorAll('a');
        assert.strictEqual(cells(0)[0].textContent.trim(), 'ignition_on');
        assert.strictEqual(cells(0)[1].textContent.trim(), 'Tracker A');

        await click(cells(0)[0]);
        assert.deepEqual(this.calls, [['deviceEvent.view', 'device_event_1']]);

        this.calls.length = 0;
        await click(cells(1)[1]);
        assert.deepEqual(this.calls, [['device.view', 'Tracker B']], "the device column opens the panel for the event's device");

        this.calls.length = 0;
        await click(findAll('tbody tr')[0].querySelector('.cell-dropdown-button .ember-basic-dropdown-trigger'));
        assert.deepEqual(
            findAll('.next-dd-item').map((el) => el.textContent.trim()),
            ['View Device Event']
        );
        await click(findAll('.next-dd-item')[0]);
        assert.deepEqual(this.calls, [['deviceEvent.view', 'device_event_1']]);
    });

    test('a response that is not a list leaves the table empty rather than throwing', async function (assert) {
        this.events = undefined;

        await render(hbs`<Map::Drawer::DeviceEventListing />`);

        assert.dom('tbody tr td.next-table-empty-state-cell').exists();
        assert.dom().includesText('No device events');
    });

    test('a failed load is reported and leaves the empty state', async function (assert) {
        this.queryFails = true;

        await render(hbs`<Map::Drawer::DeviceEventListing />`);

        assert.deepEqual(this.calls.at(-1), ['serverError', 'device events unavailable']);
        assert.dom('tbody tr td.next-table-empty-state-cell').exists();
        assert.dom().includesText('No device events');
    });
});
