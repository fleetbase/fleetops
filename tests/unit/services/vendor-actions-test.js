import { module, test } from 'qunit';
import { setupTest } from 'dummy/tests/helpers';
import Service from '@ember/service';

class IntlServiceStub extends Service {
    t(key) {
        return key;
    }
}

class ResourceContextPanelStub extends Service {
    open(config) {
        return config;
    }
}

module('Unit | Service | vendor-actions', function (hooks) {
    setupTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:intl', IntlServiceStub);
        this.owner.register('service:resource-context-panel', ResourceContextPanelStub);
    });

    test('it exists', function (assert) {
        let service = this.owner.lookup('service:vendor-actions');
        assert.ok(service);
    });

    test('panel.view uses the vendor panel header', function (assert) {
        let service = this.owner.lookup('service:vendor-actions');
        let config = service.panel.view({ id: 'vendor_1', name: 'Acme Transport' });

        assert.strictEqual(config.header, 'vendor/panel-header');
        assert.deepEqual(
            config.tabs.map((tab) => tab.key),
            ['overview']
        );
    });
});
