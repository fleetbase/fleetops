// Must come first: defines the host-console config module ember-core's url utils import at load time.
import './helpers/console-config-shim';
import Application from 'dummy/app';
import config from 'dummy/config/environment';
import * as QUnit from 'qunit';
import { setApplication } from '@ember/test-helpers';
import { setup } from 'qunit-dom';
import { start } from 'ember-qunit';
import { forceModulesToBeLoaded, sendCoverage } from 'ember-cli-code-coverage/test-support';

setApplication(Application.create(config.APP));

setup(QUnit.assert);

// A test that never settles would otherwise stall the whole run until testem's 120s browser
// timeout kills the browser — which loses every result after it and the coverage upload. With a
// per-test timeout QUnit fails just that test ("Test took longer than 60000ms") and moves on.
QUnit.config.testTimeout = 60000;

// Evaluate every bundled module after the suite finishes so files no test imported still appear
// in the coverage denominator, then post the collected coverage to the reporting middleware.
//
// WHY THIS IS NOT JUST `QUnit.done`, which is what the addon's README shows:
//
// The POST to /write-coverage carries several MB for this addon, because a per-file 100% gate needs
// every one of ~750 modules force-loaded. In CI mode testem tears the browser down as soon as QUnit
// reports the run finished, which truncates that upload mid-body — the server logs
// `BadRequestError: request aborted` from raw-body and writes nothing at all. It is size-dependent,
// so it looks like flakiness: in ember-ui, fast filtered runs produced an artifact about 2 times
// in 9 while full runs always did.
//
// `Testem.afterTests` hands us a callback that testem WAITS for, so the upload completes before
// teardown. It does not fire under `--server`, hence the branch.
//
// Upstream: https://github.com/ember-cli-code-coverage/ember-cli-code-coverage/issues/420
//           https://github.com/testem/testem/issues/1577
if (config.APP.isRunningWithServerArgs) {
    QUnit.done(async function () {
        forceModulesToBeLoaded();
        await sendCoverage();
    });
} else {
    // eslint-disable-next-line no-undef
    Testem.afterTests(function (testemConfig, data, callback) {
        forceModulesToBeLoaded();
        sendCoverage(callback);
    });
}

start();
