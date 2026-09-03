# DEFECTS

Findings from the `addon/` test-coverage campaign that need a decision or a fix. Fixed entries are
removed once they ship — this file is a worklist, not a changelog. Git history is the changelog.

Scope is the Ember addon under `addon/` only. The PHP backend under `server/` has its own suite and
its own 100% gate; nothing here touches it.

## Format

```
## N. `addon/path/to/file.js` — one-line summary

**Status:** OPEN | FIXED (where) | WONTFIX (reason) | NEEDS DECISION
**Found:** how it surfaced
**Evidence:** what proves it, traced — callers, branch counts, grep results. Never "appears unused".
**Impact:** what it costs a user, or none
**Fix:** what to do, and what makes it more than a one-liner if it is
```

Earn the claim before writing it down. "Not referenced by a template" is not "dead code" is not
"broken", and current behaviour is often deliberate. Use `NEEDS DECISION` when the resolution is a
product choice; those are Ron's to make.

## Conventions

- **Every `istanbul ignore` in the addon carries a reason naming the specific thing that makes the
  code unreachable** — the caller that always passes the argument, the template that disables the
  control, the constructor that assigns the field first. An ignore without that trace is a bug
  waiting to be reintroduced, not a coverage exemption.
- **`istanbul ignore next` does not attach to an object-property value or to a destructured
  parameter in some positions.** Where it will not take, hoist the expression into a local `const`
  and put the comment above that.
- **An ignore inside a method body does not ignore the method.** The statement stops counting, but
  the function still has to be *called* to count as covered. Put the comment above the method when
  the method itself is what cannot run.
- **A local pass is not a CI pass, and the gap is usually window focus.** Headless Linux Chrome
  never gives the page focus and macOS Chrome does, so anything downstream of focus differs.
  Coverage that arrives incidentally, from an event the browser happened to send, is the coverage
  that disappears in CI. Cover the path on purpose instead.
- **`Browser timeout exceeded: 120s` naming a specific test is usually navigation, not a hang.**
  Clicking a real `<LinkTo>` in a rendering test either starts a transition the test app cannot
  service, or — for a modifier-held click — follows the `href` and navigates away from the
  harness. Hold the modifier *and* suppress the default action.
- **The coverage upload runs in `Testem.afterTests`, not `QUnit.done`.** See the comment in
  `tests/test-helper.js`; a plain `QUnit.done` truncates the multi-MB POST on teardown.

---

# Open

## 1. `@fleetbase/ember-core` — `tracked-built-ins` is imported but not declared, so every `universe/*` service fails to instantiate in this package's test app

**Status:** FIXED (here, by adding `tracked-built-ins` as a devDependency — the upstream package.json gap remains in ember-core)
**Found:** Baseline run of the pre-existing suite: 775 of 827 tests failed, ~490 of them with
`Failed to create an instance of 'service:universe/registry-service'. Most likely an improperly
defined class or an invalid module export.` In isolation the same tests fail differently, so it is
a cascade.
**Evidence:** In the browser after the first rendering test: `require('tracked-built-ins')` throws
"Could not find module `tracked-built-ins`"; `require('@fleetbase/ember-core/contracts/universe-registry')`
throws the same (it imports `TrackedMap` from it); `requirejs.entries['@fleetbase/ember-core/services/universe/registry-service'].state`
is `pending` with `exports.default === undefined`. ember-core's package.json declares neither a
dependency nor a peerDependency on `tracked-built-ins`; the host console supplies it in production.
The first lookup throws inside the first test's render, the loader leaves the half-evaluated module
in place, ember-resolver's `_extractDefaultExport` returns the namespace object (no `create`), the
Application registry caches that resolution, and every later test asserts on it.
**Impact:** None for users (the console bundles the package). For this repo it made the entire
suite un-runnable.
**Fix:** `pnpm add -D tracked-built-ins@^3.4.0` here (done; ember-auto-import bundles it into the
dummy app). Upstream: ember-core should declare it as a peerDependency.

## 2. `@fleetbase/ember-core` — top-level imports of host-console modules (`@fleetbase/console/config/environment` in the url utils, `@fleetbase/console/extensions` in `universe/extension-manager`)

**Status:** FIXED (here, by `tests/helpers/console-config-shim.js`, imported first in `tests/test-helper.js`)
**Found:** `tests/unit/utils/vendor-integration-test.js` could not be loaded: "Could not find module
`@fleetbase/console/config/environment` imported from `@fleetbase/ember-core/utils/console-url`".
**Evidence:** `addon/utils/vendor-integration.js` imports `@fleetbase/ember-core/utils/api-url`, which
imports `./console-url`, which imports the host console's config module at load time. The dummy app
has no such module. Under coverage `forceModulesToBeLoaded()` evaluates every addon module, so every
transitive importer would be affected, not just this one test.
**Impact:** None for users. Blocks testing anything that imports those utils.
**Fix:** The shim `define`s the config module with `environment`, `API.host`, `API.namespace` and
`osrm` keys (the only keys those utils read), and the extensions module with a `getExtensionLoader`
that returns `undefined` (the extension manager then warns "no loader registered" and continues).
Test-only; nothing ships. `universe/extension-manager` is instantiated by every rendering test
through `universe` → so without the second shim every rendering test still failed.

## 3. `tests/unit/initializers/*` and `tests/unit/instance-initializers/*` — import from `dummy/…` paths that do not exist

**Status:** FIXED (imports now target `@fleetbase/fleetops-engine/initializers/…` and `…/instance-initializers/…`)
**Found:** Eight "TestLoader Failures … could not be loaded" entries in the baseline run.
**Evidence:** The engine's initializers live only under `addon/`; engines do not re-export
initializers into `app/` (they run inside the engine instance), so `dummy/initializers/x` was never
a module. The `ember generate` blueprint for an app wrote the `dummy/` path.
**Impact:** None for users. Eight test files never executed.
**Fix:** Done. Note these tests are still blueprint scaffolds (`assert.ok(true)` after boot) — see #4.

## 5. ember-intl — `IntlService` hydrates every bundled locale and `@formatjs/intl` throws `MISSING_DATA` for `mn-mn`

**Status:** FIXED (here, by `tests/dummy/config/ember-intl.js` with `includeLocales: ['en-us']`)
**Found:** After #1–#3 were fixed, every rendering test failed in `setupIntl`'s `beforeEach` with
`[@formatjs/intl Error MISSING_DATA] Missing locale data for locale: "mn-mn" in Intl.NumberFormat`,
in headless Chrome 152 and in the Claude browser pane alike.
**Evidence:** Stack: `new IntlService` → `hydrate` → `addTranslations` (for each of the eight
bundled locales) → `getOrCreateIntl` → `createIntl`, which calls `onError` when
`Intl.NumberFormat.supportedLocalesOf([locale])` is empty; ember-intl 6.3's `onError` rethrows.
`Intl.NumberFormat.supportedLocalesOf(['mn-mn'])` returns `[]` in this Chrome while the other
seven locales are supported.
**Impact:** None for users (the console runs in browsers with full ICU, and a real user picks one
locale). It blocked the whole suite here.
**Fix:** Bundle only `en-us` into the dummy app. Tests needing another locale call
`addTranslations` from `ember-intl/test-support`.

## 6. `addon/components/admin/avatar-management.js`, `avatar-manager.js` — render creates a `file` record the dummy app has no model for

**Status:** FIXED (here, by `tests/dummy/app/models/file.js`, a minimal stand-in declaring only the attributes the avatar components read)
**Found:** Coverage run after #5: "Global error: Uncaught Error: Assertion Failed: No model was
found for 'file' and no schema handles the type" while executing
`Integration | Component | admin/avatar-management: it renders`; the same for `avatar-manager`.
**Evidence:** `@fleetbase/fleetops-data` ships 59 models and `@fleetbase/ember-core` none; `file`
is a model of the host console app. The error is thrown from an ember-concurrency task, so it
surfaces as an uncaught global error rather than a test assertion.
**Impact:** None for users. Two scaffold tests fail, and an uncaught error mid-run is exactly the
kind of thing that destabilises the rest of the suite.
**Fix:** Done. The same class of gap exists for other host-console models the addon queries and
`@fleetbase/fleetops-data` does not ship — `category`, `comment`, `custom-field`,
`fuel-provider-sync-run`, `report`, `schedule*`, `user` — add a stand-in under
`tests/dummy/app/models/` the first time a test reaches one.

## 7. testem — `bail_on_uncaught_error` (default `true`) ended the run at the first uncaught asynchronous error

**Status:** FIXED (`testem.js`: `bail_on_uncaught_error: false`)
**Found:** Two consecutive coverage runs reported only 5 and 7 tests out of 832 with a clean
`1..N` summary and no disconnect message, both ending right where the avatar components threw
their uncaught `file`-model error from an ember-concurrency task.
**Evidence:** `testem/lib/runners/browser_test_runner.js` `onGlobalError`: when
`bail_on_uncaught_error` is set it records one "Global error" result, calls `onAllTestResults()`
and `finish()`; `testem/lib/config.js` defaults it to `true`. `ember-ui` never hit this because its
suite has no uncaught async errors left.
**Impact:** None for users. For the campaign, one stray rejection would hide every test after it
and produce no coverage artifact.
**Fix:** Done. Uncaught errors are still reported as failing "Global error" entries and still fail
the run.

## 8. `@fleetbase/ember-core/services/fetch` — reads `config.API.host` from the consuming app's config at module load

**Status:** FIXED (here, `tests/dummy/config/environment.js` sets `ENV.API` in the test environment)
**Found:** `avatar-picker` and `order/customer-avatar-stack` scaffolds: "Global error: TypeError:
Cannot read properties of undefined (reading 'host')".
**Evidence:** `fetch.js:13` imports `ember-get-config` (the dummy app's config, not the console
shim from #2) and line 22 reads `config.API.host` at module evaluation; the dummy config had no
`API` key.
**Impact:** None for users. Uncaught error at module load for anything injecting `fetch`.
**Fix:** Done; the host is `http://localhost:8000`, which nothing in the suite is expected to reach.

## 9. `tests/integration/components/layout/fleet-ops-sidebar-test.js` — assigned `window.location.href` on the real window and navigated the browser away

**Status:** FIXED (`setupWindowMock(hooks)` added to the module)
**Found:** Two consecutive full runs died with "Browser timeout exceeded: 120s … while executing
test: layout/fleet-ops-sidebar: it keeps block usage backwards compatible", i.e. the test *after*
the one that did the damage. `QUnit.config.testTimeout` never fired, which rules out a hung
promise: the page was gone.
**Evidence:** The preceding test, "it opens registry item nested context on initial virtual route
entry", does `window.location.href = '/fleet-ops/management/contracts'` via `import window from
'ember-window-mock'` — but the module never called `setupWindowMock(hooks)`, and without it that
import is a pass-through to the real `window`. The file was written before `ember-window-mock`
was even a dependency (added today), so it had never run.
**Impact:** None for users. It killed every full run at test 133 of 832 and starved the coverage
upload; see the brief's trap #7.
**Fix:** Done. Rule for Phase B: any test that imports `ember-window-mock` calls
`setupWindowMock(hooks)`; any test touching `location`, `open`, storage or `matchMedia` uses it.

## 10. Dummy app — no `hostRouter` service (110 failures: "Attempting to inject an unknown injection: 'service:hostRouter'")

**Status:** FIXED (`tests/dummy/app/services/host-router.js`, a recorded-call router stub on `tests/dummy/app/utils/stub-evented-service.js`)
**Found:** First complete run (832 tests): the single largest failure class.
**Evidence:** `hostRouter` is one of the services the console injects into engines
(`@fleetbase/ember-core/exports/services`); no package ships it. The addon uses
`hostRouter.transitionTo` at 272 sites, `.refresh` 58, `.currentRouteName` 12, `.on/.off` once each.
**Impact:** None for users. Every component/controller/route injecting `hostRouter` failed to
instantiate in tests.
**Fix:** Done; transitions resolve immediately and are recorded on `calls`.

## 11. Dummy app — `EXTEND_PROTOTYPES: false` while the console runs with `true` (85 failures: "this.iconContainers.pushObject is not a function")

**Status:** FIXED (`tests/dummy/config/environment.js` mirrors the console: `EXTEND_PROTOTYPES: true`)
**Found:** First complete run: second-largest failure class.
**Evidence:** `console/config/environment.js:16` sets `EXTEND_PROTOTYPES: true`. The failing call
is in `@fleetbase/ember-ui/addon/components/content-panel.js:167` (`@tracked iconContainers = []`
then `.pushObject`), which only works with array prototype extensions; ember-ui's own dummy app has
them off, so that is a latent ember-ui finding, not a fleetops one. The blueprint dummy config here
defaulted to `false`.
**Impact:** None for users (the console enables them). Tests were exercising code under a runtime
the engine never sees.
**Fix:** Done. Note for Phase B: do not "fix" addon code that relies on prototype extensions; the
host guarantees them.

## 4. `tests/` — 223 blueprint scaffolds that were never green

**Status:** OPEN (this is the bulk of Phase B)
**Found:** Baseline run.
**Evidence:** 207 of 248 integration tests are the untouched `ember generate component` scaffold
(`await render(hbs\`<X />\`); assert.dom().hasText('')` followed by the block-form render), and 16
unit tests are `let result = fn(); assert.ok(result);` scaffolds. Most of the rendering scaffolds
fail because the component asserts on a missing required argument, and the unit ones because the
util needs input. The suite does not run in CI (`.github/workflows/ember.yml` only lints and
builds), so nothing ever caught this.
**Impact:** None for users. They contribute nothing to coverage and mask the true baseline.
**Fix:** Replace each with a real test as its component/util is covered in Phase B. Never `skip`
them; a scaffold that cannot be replaced yet stays red and is listed in `COVERAGE-PROGRESS.md`.
