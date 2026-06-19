import Component from '@glimmer/component';
import Service from '@ember/service';
import { setComponentTemplate } from '@ember/component';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

let queries;

class StoreStub extends Service {
    query(type, params) {
        queries.push({ type, params });

        if (type === 'sensor') {
            return Promise.resolve([{ id: 'sensor_1', name: 'Temperature Sensor' }]);
        }

        if (type === 'device-event') {
            return Promise.resolve([{ id: 'event_1', event_type: 'ignition_on' }]);
        }

        return Promise.resolve([]);
    }
}

class SensorActionsStub extends Service {
    panel = { view() {} };
    transition = { view() {} };
}

class DeviceEventActionsStub extends Service {
    panel = { view() {} };
    transition = { view() {} };

    markProcessed() {
        return Promise.resolve();
    }
}

class TabularStub extends Component {
    get rowCount() {
        return this.args.data?.length ?? 0;
    }

    get currentPage() {
        return this.args.data?.meta?.current_page;
    }
}

module('Integration | Component | device/panel-tabs', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        queries = [];

        this.owner.register('service:store', StoreStub);
        this.owner.register('service:sensor-actions', SensorActionsStub);
        this.owner.register('service:device-event-actions', DeviceEventActionsStub);
        this.owner.register(
            'component:layout/resource/tabular',
            setComponentTemplate(
                hbs`
                    <div
                        data-test-tabular
                        data-resource={{@resource}}
                        data-pagination={{if @pagination "true" "false"}}
                        data-row-count={{this.rowCount}}
                        data-current-page={{this.currentPage}}
                    ></div>
                `,
                TabularStub
            )
        );

        this.set('device', { id: 'device_1' });
    });

    test('sensor tab renders compact array data with pagination disabled', async function (assert) {
        await render(hbs`<Device::PanelTabs::Sensors @resource={{this.device}} />`);

        assert.dom('[data-test-tabular]').hasAttribute('data-resource', 'sensor');
        assert.dom('[data-test-tabular]').hasAttribute('data-pagination', 'false');
        assert.dom('[data-test-tabular]').hasAttribute('data-row-count', '1');
        assert.dom('[data-test-tabular]').hasAttribute('data-current-page', '1');
        assert.deepEqual(queries[0], {
            type: 'sensor',
            params: { device_uuid: 'device_1', limit: 10, sort: '-updated_at' },
        });
    });

    test('events tab renders compact array data with pagination disabled', async function (assert) {
        await render(hbs`<Device::PanelTabs::Events @resource={{this.device}} />`);

        assert.dom('[data-test-tabular]').hasAttribute('data-resource', 'device-event');
        assert.dom('[data-test-tabular]').hasAttribute('data-pagination', 'false');
        assert.dom('[data-test-tabular]').hasAttribute('data-row-count', '1');
        assert.dom('[data-test-tabular]').hasAttribute('data-current-page', '1');
        assert.deepEqual(queries[0], {
            type: 'device-event',
            params: { device_uuid: 'device_1', limit: 10, sort: '-created_at' },
        });
    });

    test('tabs without a device keep empty array data safe for tabular rendering', async function (assert) {
        this.set('device', null);

        await render(hbs`<Device::PanelTabs::Sensors @resource={{this.device}} />`);

        assert.dom('[data-test-tabular]').hasAttribute('data-pagination', 'false');
        assert.dom('[data-test-tabular]').hasAttribute('data-row-count', '0');
        assert.dom('[data-test-tabular]').hasAttribute('data-current-page', '1');
        assert.deepEqual(queries, []);
    });
});
