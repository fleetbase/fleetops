import { setupApplicationTest as upstreamSetupApplicationTest, setupRenderingTest as upstreamSetupRenderingTest, setupTest as upstreamSetupTest } from 'ember-qunit';
import { setupIntl, addTranslations } from 'ember-intl/test-support';
import hostTranslations from './host-translations';

// This file exists to provide wrappers around ember-qunit's
// test setup functions. This way, you can easily extend the setup that is
// needed per test type.

function setupApplicationTest(hooks, options) {
    upstreamSetupApplicationTest(hooks, options);

    // Additional setup for application tests can be done here.
    //
    // For example, if you need an authenticated session for each
    // application test, you could do:
    //
    // hooks.beforeEach(async function () {
    //   await authenticateSession(); // ember-simple-auth
    // });
    //
    // This is also a good place to call test setup functions coming
    // from other addons:
    //
    // setupIntl(hooks); // ember-intl
    // setupMirage(hooks); // ember-cli-mirage
}

function setupRenderingTest(hooks, options) {
    upstreamSetupRenderingTest(hooks, options);

    // Instantiate the intl service before the first render. ember-intl's constructor calls
    // `setLocale`, which writes the tracked `_locale`; when the service is first looked up lazily
    // from inside a render (any component with `@service intl` or a `{{t}}` helper) that write
    // lands in the same computation that already consumed the tag and Ember asserts
    // "You attempted to update `_locale` ... already used previously in the same computation".
    setupIntl(hooks, 'en-us');

    // Keys the addon renders but the host console defines (this package's translations/ only
    // carries `fleet-ops.*` and the shared menu/resource keys). Test-only; nothing ships.
    hooks.beforeEach(function () {
        addTranslations('en-us', hostTranslations);
    });
}

function setupTest(hooks, options) {
    upstreamSetupTest(hooks, options);

    // Additional setup for unit tests can be done here.
}

export { setupApplicationTest, setupRenderingTest, setupTest };
