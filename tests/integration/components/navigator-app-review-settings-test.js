import Service from '@ember/service';
import { module, test } from 'qunit';
import { setupRenderingTest } from 'dummy/tests/helpers';
import { render, click } from '@ember/test-helpers';
import { hbs } from 'ember-cli-htmlbars';

const status = (overrides = {}) => ({
    type: 'navigator',
    enabled: false,
    phone: '+12025550144',
    phone_display: '+1 202 555 0144',
    has_code: false,
    code_generated_at: null,
    enabled_at: null,
    ...overrides,
});

class FetchStubService extends Service {
    requests = [];
    current = status();
    get(url, query, options) {
        this.requests.push(['GET', url, options.namespace]);
        return Promise.resolve({ review_access: this.current });
    }
    put(url, body) {
        this.requests.push(['PUT', url, body]);
        if (body.enabled === true) {
            this.current = status({ enabled: true, has_code: true, code_generated_at: '2026-10-24T09:00:00+00:00' });
            return Promise.resolve({ review_access: { ...this.current, code: '482913' } });
        }
        this.current = { ...this.current, enabled: false };
        return Promise.resolve({ review_access: this.current });
    }
}

class CurrentUserStubService extends Service {
    companyId = 'company-uuid-1';
}

class ModalsManagerStubService extends Service {
    confirm(options) {
        return options.confirm({ startLoading() {}, stopLoading() {}, done() {} });
    }
}

class AbilitiesStubService extends Service {
    can() {
        return true;
    }
    cannot() {
        return false;
    }
}

class NotificationsStubService extends Service {
    success() {}
    serverError() {}
}

module('Integration | Component | navigator-app-review-settings', function (hooks) {
    setupRenderingTest(hooks);

    hooks.beforeEach(function () {
        this.owner.register('service:fetch', FetchStubService);
        this.owner.register('service:current-user', CurrentUserStubService);
        this.owner.register('service:modals-manager', ModalsManagerStubService);
        this.owner.register('service:abilities', AbilitiesStubService);
        this.owner.register('service:notifications', NotificationsStubService);
    });

    test('it turns App Review access on for the organization, shows the code once and turns it off', async function (assert) {
        const fetch = this.owner.lookup('service:fetch');

        await render(hbs`<NavigatorAppReviewSettings />`);

        assert.deepEqual(fetch.requests[0], ['GET', 'review-access/navigator/company-uuid-1', 'int/v1']);
        assert.dom('[data-test-navigator-app-review-status]').hasText('Off');
        assert.dom('[data-test-navigator-app-review-phone]').includesText('+1 202 555 0144');

        await click('[data-test-navigator-app-review-turn-on]');
        assert.deepEqual(fetch.requests[1], ['PUT', 'review-access/navigator/company-uuid-1', { enabled: true }]);
        assert.dom('[data-test-navigator-app-review-status]').hasText('On');
        assert.dom('[data-test-navigator-app-review-code]').includesText('482913');

        await click('[data-test-navigator-app-review-turn-off]');
        assert.deepEqual(fetch.requests[2], ['PUT', 'review-access/navigator/company-uuid-1', { enabled: false }]);
        assert.dom('[data-test-navigator-app-review-status]').hasText('Off');
        assert.dom('[data-test-navigator-app-review-code]').doesNotExist();
    });
});
