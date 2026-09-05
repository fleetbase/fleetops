'use strict';

module.exports = {
    test_page: 'tests/index.html?hidepassed',
    disable_watching: true,
    launch_in_ci: ['Chrome'],
    launch_in_dev: ['Chrome'],
    browser_start_timeout: 120,
    // The coverage upload runs inside Testem.afterTests, which testem waits for (see
    // tests/test-helper.js). That payload is several megabytes once every module is force-loaded,
    // and the default 10s disconnect timeout is not enough for it — testem kills the browser
    // mid-upload and reports `Browser timeout exceeded: 10s` as a test error, failing the run even
    // though every test passed and the report was written.
    browser_disconnect_timeout: 120,
    // testem's default is to end the whole run at the first uncaught (asynchronous) error, reporting
    // only the tests that ran before it. With ~830 tests that turns one stray rejection into a
    // report that hides everything after it. The error is still reported as a failing "Global error"
    // entry and still fails the run; it just no longer truncates it.
    bail_on_uncaught_error: false,
    browser_args: {
        Chrome: {
            ci: [
                // --no-sandbox is needed when running Chrome inside a container
                process.env.CI ? '--no-sandbox' : null,
                '--headless',
                '--disable-dev-shm-usage',
                '--disable-software-rasterizer',
                '--mute-audio',
                '--remote-debugging-port=0',
                '--window-size=1440,900',
            ].filter(Boolean),
        },
    },
};
