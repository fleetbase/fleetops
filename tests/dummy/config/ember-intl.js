'use strict';

/**
 * ember-intl configuration for the dummy test app only (ember-intl resolves this file relative to
 * `tests/dummy/config/environment.js`; host apps are unaffected).
 *
 * The addon ships eight locales under `translations/`. ember-intl's `IntlService` hydrates every
 * bundled locale in its constructor, creating a `@formatjs/intl` instance per locale, and
 * `@formatjs/intl` throws `MISSING_DATA` when the browser's ICU has no data for one of them
 * (`Intl.NumberFormat.supportedLocalesOf(['mn-mn'])` is empty in Chrome 152, headless included).
 * Bundling only `en-us` keeps the test app deterministic across browsers; tests that need another
 * locale add its strings with `addTranslations` from `ember-intl/test-support`.
 */
module.exports = function (/* environment */) {
    return {
        includeLocales: ['en-us'],
    };
};
