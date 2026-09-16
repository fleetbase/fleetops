# Testing the PetroApp fuel integration

## Sandbox first

1. Open **Connectivity → Fuel Integrations**, create a PetroApp connection, and name it `PetroApp Sandbox`.
2. In **Credentials**, select **Sandbox (PetroApp staging)** and **Integration Token (WS-SK)**. Enter the test token supplied by PetroApp.
3. Leave **Base URL override** empty. The displayed URL should be `https://app-public.staging.petroapp.app/webservice`.
4. Run **Test Connection**. A successful result confirms authenticated vehicle access; it does not establish that bills exist in a selected date range.
5. Save the connection. Initially disable **Create Fuel Reports after vehicle match**, then sync a small date window containing known test bills.
6. Check Fuel Transactions for dates, SAR amounts, liters, vehicle matching, and unmatched rows. Repeat the same sync to check for duplicate records. Enable Fuel Report creation only after reviewing the matches.

## Production

Create a separate `PetroApp Production` connection to keep sandbox transactions separate. Select **Production**, leave the override empty, and use the AlrashedCement company's production integration token with **WS-SK** authentication. The URL should be `https://app.petroapp.com.sa/webservice`.

Generate the company integration token at [PetroApp API Token](https://app.petroapp.com.sa/profile/api-token). The provider also supports **Bearer API Token**: obtain that token by exchanging company username/password at `POST get_apiKey`, as described in the [PetroApp documentation](https://service.petroapp.com.sa/). The Fleet-Ops form accepts tokens; it does not perform username/password login.

Repeat the connection test and small-window transaction review in production before enabling automatic report creation. Do not reuse the test token in production.

## Existing connections

Previously, connections without a Base URL override always contacted staging, even when labeled Production. Review existing connections and set **Sandbox** when they use test credentials. The selected environment now controls the default endpoint. Explicit URL overrides continue to take precedence.

Connections without an authentication method first use Bearer for compatibility. If PetroApp responds with `Secret key is not found`, the same read-only request is retried once with WS-SK. Explicit authentication choices are never changed. New connections default to WS-SK. Connection diagnostics report the actual authentication method, environment, host, and HTTP status without exposing the token.

## Verification on September 15, 2026

- The supplied staging token was accepted with `WS-SK`; vehicle metadata reported 123 vehicles.
- The same token sent as Bearer returned HTTP 200 with `Secret key is not found`. This is now treated as a failed connection.
- Bills for September 8–15 returned a valid empty result. A wider sandbox read returned 3,952 bills, with June 23 among the newest dates. Importing June 23 through the local console saved one bill (4 L, SAR 3); repeating that range updated the same bill with no duplicate. It remains unmatched because the local account has no corresponding vehicle.
- Vehicle responses use `data.meta` / `data.links` pagination, while bills use `data.last_page` / `data.next_page_url`. Both formats are supported.
- Diagnostic log timestamps use ISO 8601 UTC, such as `2026-09-15T08:30:00.000Z`, with Western digits and a stable event time.
- The updated PHP provider passed the staging connection check and read 20 vehicles across two pages. The station endpoint timed out on both read-only attempts.
- Production authentication has not been tested; company production credentials are required.

The token-only compatibility path was also verified through the running local API container: Sandbox, WS-SK, HTTP 200, and 123 vehicles. The focused provider suite passed 20 tests (84 assertions), including bill-number identity and pagination-limit failures.

## Saving the connection

The linked `fleetops-data` package must declare the `credentials` model attribute so Ember Data includes the tested token in `Create Integration`. Its serializer omits absent credentials on later updates because API responses do not return tokens. Deploy the data package fix alongside the Fleet-Ops provider changes. After updating the local frontend, reload the console to recreate records with the corrected model schema.


## Functional and design audit

The integration list uses formatted dates and navigable names. Overview shows persisted transaction, unmatched, report, volume, and currency totals. Sync provides a purchase-date range, live progress, explicit empty results, and readable run history. Transactions has search, status, vehicle, and date filters with an edge-to-edge table. Matching opens a connection-scoped review list and searchable vehicle/order selectors. Settings opens the editor directly.

Sync activity is refreshed through a company-scoped `/fuel-provider-connections/{id}/activity` endpoint without refreshing the host router or replacing Ember Data record identities. Sync history records the requested dates, distinguishes new from updated bills, and retains partial progress on failure. Background imports for a connection are serialized; terminal worker failures are recorded visibly. A later failed run preserves the last successful summary.

PetroApp `bill_number` is used as the stable transaction identifier, and split plate letters/numbers are combined for matching. Re-import preserves reviewed/ignored decisions. Ambiguous vehicle identifiers remain unmatched. Manual matching persists the Fuel Report link, and repeated imports/matches reuse that report. Explicitly empty matching rules and disabled automatic report creation are honored.

### Local verification

- Authenticated browser: connection index navigation, overview totals, empty recent sync, populated June 23 sync, repeat import, status filtering and clearing, matching review navigation, transaction details, and vehicle search. No console errors in the final checked workflow.
- PHP: 57 focused tests passed (397 assertions), covering provider HTTP contracts, imports, company/connection isolation, matching/report persistence, activity totals, filters, and queue behavior. Test files are run separately because their existing namespaced event fixtures conflict when combined.
- Data package: 22 tests passed. Date/outcome formatting: 2 Node tests passed.
- Engine: five focused route/controller tests reported passing. The existing engine test runner did not exit after reporting results and was stopped; this is not a clean full-suite run.
- Changed frontend files pass ESLint and template lint. PHP changes pass focused tests and formatting checks.

### Deployment requirements

Deploy Fleet-Ops together with the linked `fleetops-data` model/serializer fixes. Run the new `2026_09_15_000002_scope_fuel_transactions_to_connection` migration before syncing: bill uniqueness now includes the connection, so separate sandbox and production accounts cannot overwrite each other's transactions. This migration has been applied locally. Reload Octane and restart queue workers after deploying; both were reloaded locally for verification.

The rollback restores the old tighter uniqueness rule and will fail if distinct connections already contain the same provider bill ID; reconcile those records before rolling back rather than deleting them automatically.

Production authentication and a production purchase/vehicle/report check remain required using AlrashedCement credentials. Local sandbox verification does not establish production acceptance. Use a separate production connection and a small date range with known purchases.


## Connection-test follow-up

The September 15 console trace identifies a remaining route-level identity collision: URL parameters contain public IDs, while the serializer uses UUIDs as Ember Data primary keys. Connection details, connection editing, and transaction details now use `queryRecord({ public_id, single: true })`, following the existing telematics route convention. They no longer reserve a public ID as a primary record ID through `findRecord`.

The Test action on details and in the integration list opens the standard Fleetbase modal. It waits for Run Test, displays progress, preserves success/failure results and provider metadata, offers Test Again and Copy diagnostics, and records stable ISO 8601 UTC timestamps. Only approved diagnostic metadata fields are displayed. Testing updates connection health without refreshing the router.

Live sandbox verification: Run Test and Test Again both returned HTTP 200, WS-SK authentication, and 123 vehicles; the dialog remained open and the integration details remained accessible after closing and reopening. Nine focused route/dialog tests reported passing, and 15 connection model/serializer tests passed, including repeated public-ID lookups returning the same UUID record. The engine runner still required cleanup after reporting results.

## Transaction review refinements (September 16)

The ledger has one header and semantic status badges. Fuel Report actions are shown only for linked reports. Vehicle and order actions open searchable matching dialogs; reprocess, ignore, review, and bulk reprocess require confirmation with a purchase summary and an explanation of the change. Failed bulk actions retain progress so retries skip completed purchases.

Transaction details use compact purchase, station/account, and vehicle-reference sections. Provider fields such as fuel type, payment method, VAT, branch, and representative are displayed with readable labels when available. Raw JSON and integration internals are not shown.

Validation: all 19 focused engine tests reported passing, including confirmation boundaries, matching endpoints, retry behavior, and provider-field presentation. JavaScript/template lint passed. The test runner required cleanup after reporting results. Live visual verification of this final refinement was blocked by repeated browser automation timeouts.
