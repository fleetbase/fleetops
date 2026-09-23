import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click, fillIn } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';
import Service from '@ember/service';

class FetchStubService extends Service {
    posts = [];

    get() {
        return Promise.resolve({ event_retention_days: 60, position_retention_days: 0, log_telemetry_activity: false });
    }

    post(url, body) {
        this.posts.push([url, body]);
        return Promise.resolve(body);
    }
}

class NotificationsStubService extends Service {
    successes = [];

    success(message) {
        this.successes.push(message);
    }

    serverError() {}
}

module('Integration | Component | admin/telematics-settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:notifications', NotificationsStubService);
    });

    test('it loads system defaults and saves them', async function (assert) {
        await render(hbs`<Admin::TelematicsSettings />`);

        assert.dom('input[type="number"]').exists({ count: 6 });
        assert.dom('input[type="number"]').hasValue('60');

        await fillIn('input[type="number"]', '45');
        await click('button.btn-primary');

        const fetch = this.owner.lookup('service:fetch');
        assert.strictEqual(fetch.posts.length, 1);
        assert.strictEqual(fetch.posts[0][0], 'fleet-ops/settings/admin-telematics-settings');
        assert.strictEqual(fetch.posts[0][1].event_retention_days, 45);
        assert.strictEqual(fetch.posts[0][1].position_retention_days, 0);
        assert.strictEqual(this.owner.lookup('service:notifications').successes.length, 1);
    });
});
