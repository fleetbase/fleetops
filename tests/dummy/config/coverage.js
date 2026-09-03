'use strict';

// Read by ember-cli-code-coverage from `ember-addon.configPath` in package.json (tests/dummy/config),
// for BOTH the istanbul babel plugin (via index.js) and the /write-coverage middleware. A copy under
// `config/` is never consulted.

module.exports = {
    // `json` is not in ember-cli-code-coverage's default reporter set (html, lcov; json-summary is
    // added automatically). scripts/check-coverage.js requires coverage-final.json — the per-file
    // detail behind the summary and the artifact Codecov-style tooling reads — so ask for it.
    reporters: ['html', 'lcov', 'json', 'json-summary'],

    excludes: [
        '*/mirage/**/*',

        // A pnpm workspace link (e.g. `@fleetbase/ember-core` symlinked for local development)
        // is compiled by this package's build, so istanbul instruments it too. That has two bad
        // consequences: a sibling package's files are held to this package's coverage threshold,
        // and the HTML reporter writes one page per file at `coverage/../ember-core/...`, which
        // resolves OUTSIDE the gitignored coverage folder and into the package root.
        '../**/*',
        '*/ember-core/**/*',
        '*/ember-ui/**/*',
        '*/fleetops-data/**/*',

        // The dummy test app: its own harness code plus the 894 generated `app/` re-export stubs
        // (`export { default } from '@fleetbase/fleetops-engine/...'`) that ember-cli merges into it.
        // Neither is first-party addon source; the gate is `addon/` only.
        'dummy/**/*',
    ],
};
