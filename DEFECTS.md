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

## 12. `addon/helpers/is-active-route.js` — re-exported a console module that does not exist anywhere

**Status:** FIXED (file deleted)
**Found:** Gate: "missing from the coverage report". The module throws on evaluation so istanbul never
registers it.
**Evidence:** One line: `export { default, isActiveRoute } from '@fleetbase/console/helpers/is-active-route'`.
No file named `is-active-route` exists in the console app (`app/`, `addon/`), in ember-ui or in
ember-core; nothing in `addon/`, `app/` or any template references `is-active-route`/`isActiveRoute`.
Untouched since the 2023-10-09 monorepo import. It has no `app/` re-export, so no host could ever
resolve it as a helper either.
**Impact:** None; it was unreachable dead weight.
**Fix:** Deleted.

## 13. `addon/components/order/details/proof.js` — imported `ember-concurrency-decorators`, which this package does not depend on

**Status:** FIXED (import switched to `ember-concurrency`, which the rest of the addon already uses)
**Found:** Gate: "missing from the coverage report" — module evaluation throws "Could not find
module `ember-concurrency-decorators`".
**Evidence:** The only importer in `addon/`; the package is absent from this package.json and
node_modules and only resolves in the console because the console declares it. ember-concurrency 4
exports the same `task` decorator (used by every other component here).
**Impact:** In a host without that transitive package the order proof panel would fail to load.
Not a behaviour change here: same decorator, same semantics.
**Fix:** Done.

## 14. `addon/helpers/format-duration.js` — pure re-export that istanbul never instruments and nothing imports

**Status:** FIXED (file deleted)
**Found:** Gate: the only addon file "missing from the coverage report" after #12/#13.
**Evidence:** One line, `export { default, formatDurationValue } from '@fleetbase/ember-ui/helpers/format-duration'`;
it is the only file in `addon/` with no statement at all, and babel-plugin-istanbul emits no coverage
object for such a file. No JS in `addon/`, `app/` or `tests/` imports
`@fleetbase/fleetops-engine/helpers/format-duration`. The three templates that use
`{{format-duration}}` resolve the helper from the app namespace, which ember-ui already provides via
its own `app/helpers/format-duration.js` (this package's identical `app/helpers/format-duration.js`
re-export stays; it is harmless and outside the gate).
**Impact:** None.
**Fix:** Deleted.

## 15. `addon/components/layout/fleet-ops-sidebar.js` — five defensive defaults no caller can reach

**Status:** FIXED (defaults removed; one `istanbul ignore if` with the caller named)
**Found:** Coverage residue after the module's suite was green: `default-arg` branches at
`createBranch` (`keywords = []`), `createHubItem` (`keywords = []`), `sortByPriority`
(`items = []`), `shouldSyncInitialActiveParent` (`activePath = []`), `searchNavigation`
(`limit = 12`), plus the `!trimmedQuery` early return.
**Evidence:** `createBranch` has 7 call sites and `createHubItem` 5, all in this file, all passing
`keywords`. `sortByPriority` is called from `registryRootItems`, `registryPanelItems` (twice) and
`withRegistryItems`, always with an array literal or `.map()` result. The two actions are only
invoked by ember-ui's `Layout::Sidebar::Navigator`: `shouldSyncInitialActiveParent` is called with
`{ activePath, routeName, currentURL, router }` (navigator.js `shouldSyncInitialActiveParent`),
and `searchProvider` is called with `{ query, items, limit: this.maxSearchResults }` only after the
navigator has itself returned on an empty trimmed query (navigator.js `searchProvider`).
Four more unreachable fallbacks in the same file: the `= []` initializers on the eight
`@tracked universe*` list fields (the constructor's `createMenuItemsFromUniverseRegistry()` assigns
all eight before any read, and Ember's legacy `@tracked` runs a field initializer lazily on first
read, so the initializer can never execute); `?? []` in `withRegistryItems` (the same lists are
always arrays); the `!route || route.startsWith('console.')` guard in `fullRoute` (all 14 call
sites pass an unprefixed engine route); and `?? 0` in `defaultPriorityForRoute` (every route passed
by `createItem`/`createHubItem` is a key of the priorities map — verified by diffing the two lists).
**Impact:** None; none of these fallbacks could ever take effect.
**Fix:** All deleted. The empty-query guard is kept (the arg is public API on the component)
behind `istanbul ignore if` naming the navigator as the reason.

## 16. `addon/components/layout/fleet-ops-sidebar/operations-monitor.js` — three unreferenced getters and two guards nothing can trip

**Status:** FIXED (deleted; three host-environment guards kept behind `istanbul ignore` with the reason)
**Found:** Coverage residue while covering the component.
**Evidence:** `activeResources`, `emptyMessage` and `subtitle` are referenced by neither
`operations-monitor.hbs` nor the class itself (`grep -c 'this\.<name>'` is 0 in both); the
template renders the equivalent data inline via `{{or ...}}` and `this.emptyState`.
`focusResource(resource)` is only reached from `locateDriver`/`locateVehicle`, whose only callers are
the template's `(fn this.locateDriver row.driver)` / `(fn this.locateVehicle row.vehicle)` and the
driver/vehicle row buttons — every one passes an existing row resource. In `updateListHeight` the
last fallback `this.monitorElement.parentElement` is the element `did-insert` registered, which is
mounted, so `boundary` is never null. The `typeof window` / `typeof ResizeObserver` /
`typeof requestAnimationFrame` checks guard non-browser hosts and can only be false outside Chrome.
Also unreachable: the `= new Set()` initializer on `@tracked expandedFleetIds` (assigned by
`loadFallbackResources` together with `fallbackFleets`, and only read by `isFleetExpanded`/
`toggleFleet` once fleets exist — Ember's legacy `@tracked` initializes lazily on read); the
`!query` early return in `resourceMatches` (its only callers `fleetMatches`/`driverMatches`/
`vehicleMatches` are only reached from `buildFilteredFleetRows`, which `fleetRows` calls only when
`hasQuery`); `.length ?? 0` in both count helpers (`length` is never nullish); and nine default
arguments whose every caller passes the value (`filterResources` both, `resourceMatches#fields`,
`buildFleetRows#fleets`, `buildFilteredFleetRows#fleets`, `buildExpandedFleetRows#depth`,
`collectFleetKeys`, `sortOnlineFirst`, `resourcesById`). The `!listElement || !monitorElement`
guard in `updateListHeight` cannot trip either: both `did-insert` registrations happen in the same
render before the scheduled frame, and teardown cancels the frame. In `performEmptyStateAction` the
final `if (this.activeTab === 'fleets')` follows early returns for the only other two tabs, so it is
always true (made unconditional). In `resourceArray` the `Array.isArray(resources) ? resources : []`
consequent is unreachable while `EXTEND_PROTOTYPES` is on (the console's setting): every native
array already answers `toArray()` one line earlier — kept behind `istanbul ignore next`.
**Impact:** None.
**Fix:** Getters, guards, initializer, `?? 0`s and defaults deleted; the environment guards and the
element guard carry `istanbul ignore` comments naming the reason.

## 17. `addon/components/cell/attached-vehicle.js`, `telematic-provider.js` — click guards their templates already enforce

**Status:** FIXED (deleted)
**Found:** Coverage residue in the cell suites.
**Evidence:** `attached-vehicle.hbs` renders the `Cell::VehicleIdentity` that carries
`@onClick={{this.onClick}}` only inside `{{#if this.hasVehicle}}`, so `onClick`'s
`if (!this.hasVehicle) return;` can never be true. `telematic-provider.hbs` renders both click
targets only inside `{{#if this.telematic}}`, so `this.telematic ?? row` in `onClick` never falls
through to `row`.
**Impact:** None.
**Fix:** Both fallbacks deleted. Likewise `driver-identity.js` `assignedVehicleLabel`'s
`this.args.column ?? {}`: the getter is only read from the compact template, which the
`this.args.column?.compact` check already gated on a column being present.

## 18. `addon/utils/geojson/geo-json.js` — dead duplicate of the fleetops-data base class

**Status:** FIXED (deleted)
**Found:** Unit-utility sweep; its scaffold died with "Class constructor GeoJson cannot be invoked without 'new'".
**Evidence:** The file imported `./calculate-bounds`, which does not exist in this package (it lives
in `@fleetbase/fleetops-data/utils/geojson/`, together with the identical `GeoJson` class every
other util here already imports). Nothing in `addon/` imported it; only the generated `app/` shim
and the scaffold test referenced it.
**Impact:** None at runtime; it could never have been used without the build failing.
**Fix:** Deleted with its `app/utils/geojson/geo-json.js` shim and the scaffold test.

## 19. `addon/utils/leaflet-to-geojson.js` — `createFeatureCollectionFromLayers` always threw

**Status:** FIXED
**Found:** First real test of the function.
**Evidence:** It called `new FeatureCollection({ features })`, but the fleetops-data constructor
accepts either a GeoJSON `FeatureCollection` object or a plain array of features, and throws
`GeoJSON: invalid input for new FeatureCollection` for anything else. No caller in `addon/`
survived to notice; the function is exported API.
**Impact:** Any consumer batching drawn layers into a collection got an exception instead.
**Fix:** Pass the array. Also in this file: `normalizeToRings` ended with two identical
`return latlngs` paths (one behind a guard, one as fallthrough); merged into one.

## 20. `tests/unit/utils/map-drawer-dropdown-position-test.js` — stale against commit f784e710

**Status:** FIXED (test updated to the source contract)
**Found:** Two red assertions in the unit-utility sweep.
**Evidence:** The June test expected `position: 'fixed'`, a numeric `zIndex` and a vertical clamp
of `bottom - height - gap`. Commit f784e710 (2026-07-01) deliberately changed the util to
`position: 'absolute'`, a string `zIndex` and a `bottom - height + 8` clamp, and never touched the
test. The source is the intended behaviour (the menu is positioned inside the drawer panel).
**Impact:** None for users; the suite was red.
**Fix:** Expectations follow the source. The util now imports `window` from `ember-window-mock`
so the viewport fallback (no drawer panel) is testable deterministically.

## 21. `addon/utils/leaflet-plugin-loader.js` — dead defaults and non-browser guards

**Status:** FIXED
**Found:** Coverage residue after the loader suite was made deterministic.
**Evidence:** `normalizePath(path = '')`, `waitForLeafletGlobal({ timeoutMs = 8000 } = {})` and
`loadScript(src, { timeoutMs = 8000, isReady = null } = {})` are module-private and each has one
caller that always passes every value (`ensureLeafletPluginsReady` resolves the public defaults
first), so none of those defaults can apply. The three `typeof window/document === 'undefined'`
guards cannot be true under Testem, which only ever runs this module in Chrome.
**Impact:** None.
**Fix:** Defaults deleted; the guards carry `istanbul ignore if` with that reason. The former
test asserted on script elements synchronously after the call, but the loader appends them after
the Leaflet-global promise settles; the suite now waits for the loader's listeners and neutralises
its own script elements so no network request is made.

## 22. Three utils — defensive fallbacks with no reachable input

**Status:** FIXED (deleted)
**Found:** Coverage residue in the unit-utility sweep.
**Evidence:** `setup-customer-portal.js` guarded `customerPortalEngine?._fleetopsSetupCompleted`
two lines above an unconditional `customerPortalEngine._fleetopsSetupCompleted = true`; a null
engine threw either way. `to-calendar-date.js` used `parts.find(...)?.value ?? '0'`, but
`Intl.DateTimeFormat#formatToParts` always emits every requested part, and the surrounding
`try/catch` already returns the input date on any failure. `to-multi-polygon.js` used
`geom.coordinates ?? input.coordinates` where `geom` is either `input` or `input.geometry`, so the
fallback can only ever produce the same value.
**Impact:** None.
**Fix:** All three deleted.

## 23. `tests/helpers/index.js` — ember-local-storage proxies outlive the test app that created them

**Status:** FIXED (harness)
**Found:** Five `Unit | Service` suites died in `it exists` with "Cannot create a new tag for
`<(unknown):ember2993>` after it has been destroyed"; the same object id across three different
modules pointed at something shared outside the container.
**Evidence:** `ember-local-storage/helpers/storage` caches every `storageFor` proxy in a
module-level map. `currentUser.options`, `currentUser.cache` and `appCache.localCache` are such
proxies; the first test app to tear down destroys them, and every later app that reads
`currentUser.getOption` (the `defaultCurrency` read in the action-service constructors) or
`appCache.get` (the order-list-overlay constructor) trips the destroyed-tag assertion.
**Impact:** None for users; any test that touched user options after the first module was red.
**Fix:** `setupTest`/`setupRenderingTest`/`setupApplicationTest` now clear browser storage and
call the addon's own `_resetStorages()` after every test.

## 24. `tests/unit/services/*` — service tests that were never green

**Status:** FIXED (tests corrected to the source contract)
**Found:** Remaining red `Unit | Service` tests once #23 was fixed.
**Evidence:** `driver-actions`, `vehicle-actions` and `device-event-actions` assigned over
`service.refresh` / `service.transitionTo`, which `@action` defines as getter-only accessors
(TypeError in strict mode); the stubs now sit on the host-router the base service delegates to,
and the transition test asserts the mount-prefixed route the base service actually produces.
`leaflet-contextmenu-manager` expected one `removeAllItems` call after removal, but registration
has cleared native items before adding its own since 2023 (`createContextMenu`), so removal is
the second call. `device-actions` expected `panel.view()` without a device to return `undefined`,
but it returns the notification from `notifications.warning`. `geofence` awaited one microtask
while the canonical reload chains several promises; the tests now wait for the polygon to show.
`route-optimization` / `leaflet-routing-control` register into the application registry through
`universe.getApplicationInstance()`, which only the console sets while booting engines; those two
suites now hand the universe the test owner (an eager dummy instance-initializer was tried and
rejected: cascading `setApplicationInstance` instantiates the real `universe/menu-service`
before a suite can register its stub).
**Impact:** None.
**Fix:** As above; no source change.

## 25. `tests/unit/{controllers,routes}/connectivity/telematics/index/**` — tests left behind by the telematics route move

**Status:** FIXED (relocated)
**Found:** 16 red `it exists` / real tests whose lookups returned `undefined`.
**Evidence:** `addon/` has `connectivity/telematics/{details,edit,new}` and
`connectivity/telematics/details/{devices,events,index,sensors}`; there is no
`connectivity/telematics/index/*` subtree in either controllers or routes. The tests still looked
up the old `index/` names. Sixteen files were `git mv`'d to the new paths with their lookup strings
rewritten; the stale `index/details` controller scaffold duplicated the real
`telematics/details-test.js` and was deleted, as was
`tests/unit/routes/addon/routes/management/places/index/new-test.js`, a mis-generated duplicate of
`tests/unit/routes/management/places/index/new-test.js`. Every relocated test passes against the
current sources unchanged.
**Impact:** None for users.
**Fix:** As above.

## 26. `app/controllers/operations/{orders,routes}/index.js` — missing re-export shims

**Status:** FIXED (shims added)
**Found:** `controller:operations/routes/index` resolved to `undefined` in the dummy app, and the
`@controller('operations.orders.index')` injection in `operations/orders/index/details`
asserted "unknown injection".
**Evidence:** `addon/controllers/operations/orders/index.js` and
`addon/controllers/operations/routes/index.js` both exist, but `app/controllers/operations/orders/`
and `app/controllers/operations/routes/` only carried their child directories; the two one-line
shims the blueprint generates alongside every addon module were never committed. Inside the
engine the resolver reads `addon/` directly, so the console never noticed; a host that merges
`app/` (the dummy app, and any non-engine consumer) cannot resolve those two controllers.
**Impact:** None inside the engine.
**Fix:** The two shims added.

## 27. `tests/unit/routes/operations/orders/index/details-test.js`, `.../attachments-test.js`, `register-osrm-test.js` — stale or scaffold tests

**Status:** FIXED (tests corrected to the source contract)
**Found:** Remaining red unit tests after #25.
**Evidence:** The order-details route no longer stops sockets or removes routing controls itself;
`willTransition` delegates to the controller's `teardownRealtime()` and
`teardownRoutingControls()`, which the test never stubbed. The attachments test declared
`assert.expect(3)` for a body that makes six assertions (three `assert.step` calls, two state
checks, `verifySteps`). The register-osrm scaffold only booted an instance; it now asserts the
three registrations and hands the universe its application instance first (the routing services
register through `universe.getApplicationInstance()`, see #24).
**Impact:** None.
**Fix:** As above; no source change.

## 28. `tests/integration/components/vendor/form-test.js` (and siblings) — un-awaited fetches spill "Failed to fetch" onto the next test

**Status:** OPEN (resolves with the scaffold sweep, #4)
**Found:** `vendor/panel-header: it falls back when vendor values are missing` went red in one
of four otherwise identical full runs with `global failure: TypeError: Failed to fetch`.
**Evidence:** Every full run logs exactly four `Failed to fetch` rejections. They originate in
`it renders` scaffolds that mount real forms (`vendor/form` runs immediately before the affected
test) whose `ModelSelect`/fetch-backed children issue requests to the unreachable API host; the
rejection is not awaited by the scaffold, so QUnit attributes it to whichever test is running when
it settles. Usually that is the next scaffold, which is red anyway; timing decides.
**Impact:** None for users; one flaky green test per run at worst.
**Fix:** Replace those scaffolds with tests that stub `service:fetch` (the pattern every real
suite here already uses). Until then, treat a lone `Failed to fetch` global failure on an
otherwise green test as this defect.

## 29. Eight stale or scaffold unit/helper tests

**Status:** FIXED (tests corrected to the source contract)
**Found:** The last red non-scaffold unit tests after #27.
**Evidence:** `connectivity/devices/index/details/vehicle` expected the vehicle record as the
transition model, but commit a9eed9cb ("Fix vehicle attachment navigation", 2026-06-23)
deliberately passes `vehicle.public_id`. `settings/map` expected a payload without the
`leafletTileUrl`/`leafletDarkTileUrl` keys the controller has sent since tile URLs became
settings. `leaflet-tracking-marker` and `telematic/form` built fakes with
`Object.create(prototype)` and then assigned properties that ember-leaflet's BaseLayer exposes as
getters, or called an `@action` through the prototype (which binds `this` to the prototype);
both now shadow via property descriptors on an object that inherits the prototype.
`load-leaflet-assets` asserted synchronously on a 100ms poll and, with a stub `window.L`, let the
plugin loader append real Draw/contextmenu scripts that would execute against the stub. Worse,
both it and `leaflet-intersects-polyfill` left their 100ms polls running after the test ended
(the interval is not tied to the application), and in a full run the polyfill's leaked poll fired
while the next test had swapped `window.L` for `{}`, crashing on `L.Bounds.prototype`. Both
tests now capture `setInterval`/`clearInterval` and drive the poll by hand, keep any appended
script inert, and assert the poll is cleared; the loader's rejection is observed through
`console.debug` so the initializer's catch is covered too. The three `Integration | Helper` scaffolds
rendered `{{helper 1234}}` and expected `1234` back.
**Impact:** None.
**Fix:** As above; no source change. Note for slice runs: QUnit's `--filter "/regex/"` did not
match module names containing `\|`-escaped pipes, so a slice can silently run fewer tests than
intended — count the `ok` lines per module before trusting a slice profile.

## 30. Five map templates — `@url={{leaflet-tile-url}}` cannot render under Ember 5

**Status:** FIXED
**Found:** The first real rendering tests of `place/details`, `service-area/details` and
`zone/details` died with "A resolved helper cannot be passed as a named argument as the syntax is
ambiguously a pass-by-reference or invocation".
**Evidence:** `<layers.tile @url={{leaflet-tile-url}} />` passes a bare helper name as a named
argument; Ember 5's template compiler rejects that at render time (the same shape broke the
resource-identities suite in DEFECTS #17's iteration). `grep -rn "={{leaflet-tile-url}}" addon`
found five templates: `place/details.hbs`, `service-area/details.hbs`, `zone/details.hbs`,
`modals/place-details.hbs`, `modals/point-map.hbs`. The helper's own doc comment showed the same
form.
**Impact:** Those five map views throw when rendered on Ember 5 — a place's details panel, a
service area's and a zone's details, and the two map modals show nothing.
**Fix:** `@url={{(leaflet-tile-url)}}` in all five templates and the doc comment; the three
details views now render a Leaflet map in tests. Also trimmed the helper's `= {}` default on its
hash parameter: Ember always passes a hash object to `compute`, so the default cannot apply.

## 31. `addon/components/fuel-report/form.js`, `integrated-vendor/form.js` — actions no template invokes

**Status:** FIXED (deleted)
**Found:** Writing the first real rendering tests for the six form components.
**Evidence:** `FuelReportFormComponent#onAutocomplete` is referenced nowhere in
`fuel-report/form.hbs` (its `ModelCoordinatesInput` is mounted without an `@onAutocomplete`),
and `IntegratedVendorFormComponent`'s `showAdvancedOptions`/`toggleAdvancedOptions` are
referenced nowhere in `integrated-vendor/form.hbs` (the advanced options live in a collapsed
`ContentPanel`, which manages its own open state). A Glimmer component's actions are reachable
only from its own template, and both templates are the only renderers of these classes
(`grep -rn "FuelReport::Form\|IntegratedVendor::Form" addon`).
**Impact:** None.
**Fix:** Both removed; the classes are now empty shells like the other four form components.

## 32. `addon/components/vehicle/pill.hbs` — the pill never received its vehicle

**Status:** FIXED
**Found:** Writing the first real rendering test of the vehicle pill.
**Evidence:** The template passed `@this.resource={{this.resource}}` to ember-ui's `Pill` instead
of `@resource=...`, so the pill's click handler, online indicator (`get @resource "online"`) and
image alt never saw the vehicle: `@onClick` was invoked without the vehicle and the online dot
was always the offline colour. Its tooltip block also read `{{this.resource.name
this.resource.yearMakeModel}}`, which Glimmer treats as invoking a string as a helper and throws
the moment the tooltip opens.
**Impact:** Vehicle pills reported every vehicle offline, handed no vehicle to click handlers,
and crashed on hover.
**Fix:** `@resource={{this.resource}}` and `{{or this.resource.name this.resource.yearMakeModel}}`.

## 33. `addon/components/fleet/form.js`, `order-progress-bar.js` — fields nothing reads

**Status:** FIXED (deleted)
**Found:** Coverage residue after the first real tests of both components.
**Evidence:** `FleetFormComponent#writePermission` and `@tracked statusOptions` are referenced
by nothing: not by `fleet/form.hbs` (it reads `get-fleet-ops-options "fleetStatuses"` and
`cannot-write`), and `grep -rn "writePermission\|statusOptions" addon` finds no other reader.
`OrderProgressBarComponent` initialised `@tracked progress = 0` and stored `@tracked order`,
but its constructor always assigns `progress` before any read (so Ember's lazy tracked
initializer can never run — the same shape as DEFECTS #15) and `order` is read by nothing:
`order-progress-bar.hbs` uses the `@progress`/`@firstWaypointCompleted`/`@lastWaypointCompleted`
arguments only.
**Impact:** None.
**Fix:** All four deleted; the constructor keeps its `progress = 0` default, which is the live
default for a bar rendered without `@progress`.

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
