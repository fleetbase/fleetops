# AFAQY live telemetry

## Behavior

AFAQY uses the provider-neutral telemetry infrastructure described in [TELEMETRY_ARCHITECTURE.md](TELEMETRY_ARCHITECTURE.md). Its adapter owns authentication and payload normalization; shared services own persistence, ingestion, and diagnostics.

The existing AFAQY integration already used Units List. The new path requests 1,000 units per page with `simplify: 0` and the documented `basic` / `last_update` projection groups. It fetches all pages, places configurable batches of units (100 by default) in an encrypted durable inbox, and ingests them asynchronously. A connection in transient `error` status remains eligible; `disabled` connections and connections with `meta.telemetry_sync_enabled: false` do not.

Position webhooks use the same ingestion path and do not authenticate against AFAQY. The adapter provisionally accepts a unit object, a unit array, or a `data` envelope containing either, including `_id` with nested `data.last_update`. This is an assumption, not a verified AFAQY webhook contract. Unsupported authenticated JSON is quarantined and can be replayed after an adapter correction. Vehicle event webhooks and Signals history are outside this implementation.

`dtt` orders positions. `dts` records last contact, falling back to `dtt`. UTC receipt and processing times are recorded separately. Old signals can enter history but cannot replace newer device, vehicle, or trailer positions. Equal-time samples keep the first current position. Duplicate signals do not create another event or broadcast. `active` is not treated as connectivity.

## Stage 1 deployment

1. Apply the Fleet-Ops migrations `2026_09_15_000001_create_telematic_telemetry_tables` and `2026_09_17_000001_add_device_event_uuid_lookup_index`. The first adds shared `telematic_deliveries`, `telematic_sync_runs`, and `telematic_webhook_credentials` tables plus a composite device lookup index. The second ensures `device_events.uuid` has a usable leading index, including installations upgraded from the legacy events table. Existing telemetry data is preserved.
2. Use a shared cache with distributed locks (Redis recommended) across all scheduler and worker instances. An array cache is suitable only for isolated tests. API, queue, and scheduler services must load the same effective `APP_KEY`, cache configuration, and telemetry settings, including Compose overrides and cached Laravel configuration. Preserve the established key that decrypts existing application data; do not generate a replacement as a troubleshooting step. Cached login tokens are encrypted, scoped to account/host/password, and reused for up to 29 days; a credential change selects a new cache namespace. All API attempts for the same host/account share a rolling 60-request/minute budget.
3. Set the following environment variables and rebuild the application's configuration cache:

   ```dotenv
   AFAQY_POLLING_ENABLED=true
   AFAQY_WEBHOOKS_ENABLED=false
   ```

4. Keep the existing worker consuming the application's `default` queue, and restart long-running workers after configuration or code changes. Polling, ingestion, and broadcasts use that queue by default. The baseline requires no additional containers, dedicated queues, or custom batch size; ingestion batches default to 100 units.

   One worker can execute the whole pipeline: the polling job enqueues ingestion and returns without waiting for those jobs. Ingestion, broadcasts, and unrelated jobs then share that worker serially. Pending poll ingestion coalesces later polling ticks. This avoids accumulating obsolete sweeps, but it does not establish a particular freshness or throughput bound. Measure complete cycles using the deployment's actual worker count and workload.

   Remove stale `TELEMATICS_POLL_QUEUE`, `TELEMATICS_INGESTION_QUEUE`, `AFAQY_POLL_QUEUE`, and `AFAQY_INGESTION_QUEUE` overrides unless the existing worker consumes those queues. Jobs sent to an unconsumed queue cannot progress.

   Check that the effective queue reservation/retry period is compatible with the longest job the worker consumes, so the broker cannot make a still-running job available again. A worker's CLI timeout does not override a timeout declared by a job; manual discovery declares 3,600 seconds. This is a deployment compatibility check, not a requirement to add queues or prescribe a new reservation period as part of this patch.

5. Confirm the application scheduler executes every minute. It runs both `fleetops:sync-telematics` and `fleetops:drain-telematic-inbox`. The drain recovers accepted deliveries if broker dispatch failed or a worker stopped; no payload is acknowledged before durable persistence. Both commands use two-minute overlap leases so an interrupted scheduler cannot block telemetry for the former 24-hour default.
6. Open the connection's live telemetry panel. Verify a full polling run transitions from fetching to ingesting to completed, with expected unit/page/applied counts. `incomplete` and `partial` are failures to investigate, not complete fleet coverage.

Use the ordinary application scheduler for the baseline. Laravel executes foreground scheduled commands in registration order, so slow unrelated commands can delay polling and inbox recovery even when the telemetry queue is empty. Include scheduler execution time and application startup in diagnosis; distributed command locks are acquired only after Laravel boots. Additional scheduler isolation or worker capacity is an optional operational decision that requires separate measurement, not a prerequisite imposed by this integration.

Rate-limit retries honor `Retry-After` (bounded to one hour). Other polling failures use 15/60/180/300-second backoff, with five queue attempts. Poll HTTP requests default to a 45-second total request timeout, configurable with `TELEMATICS_REQUEST_TIMEOUT_SECONDS`, and a connection limit of at most five seconds. Each request is capped by the remaining 90-second fetch budget; changing the request setting does not extend that sweep budget. Sweeps also stop after 100 pages and report incomplete coverage. Pending poll ingestion coalesces later polling ticks. Failed ingestion retries only failed units; the original encrypted body remains available. Five failed/interrupted attempts quarantine the delivery. Internally generated polling batches are normalized per unit: a unit without a position is reported as invalid without discarding valid neighbors. Such a sweep reports partial coverage. External webhook payloads still require the adapter's supported position envelope.

## Stage 2 registration

1. Configure the application's public API URL as HTTPS and set `AFAQY_WEBHOOKS_ENABLED=true`.
2. In the connection's live telemetry panel select **Show registration URL**. Provide that exact URL to AFAQY and request **Device Signals / Positions** registration. Each URL contains the connection ID and a 256-bit random secret. The server requires both before accepting a body.
3. Redact the `key` and `token` query parameters in reverse-proxy/access logs, tracing, error reporting, and shared screenshots. The application does not log webhook bodies or secrets. Protect database backups and the application encryption key.
4. Confirm the first delivery's actual envelope, unit identifier, timestamps, content type, retry/acknowledgement behavior, and signing/header capabilities with AFAQY. Save a sanitized payload as a contract fixture. Until this is done, the UI continues to describe the adapter as provisional.
5. Inspect quarantined deliveries in a restricted server session. Correct the adapter and use **Replay delivery**. The API deliberately does not expose raw location payloads or the stored secret through diagnostics.
6. Run a 24-hour hybrid pilot before expanding. Polling continues every minute regardless of webhook capability. **Rotate secret and invalidate old URL** requires AFAQY to register the replacement URL.

A successful HTTP 200 means **durably accepted**, not successfully applied. Bad credentials return 403, non-POST requests 405, malformed JSON 422, oversized bodies 413, and unavailable persistence/capacity 503. No undocumented provider signature scheme is assumed.

## Interfaces and configuration

Existing provider interfaces and vehicle/trailer event names remain compatible. New internal endpoints are scoped to the authenticated company:

- `GET telematics/{id}/telemetry-diagnostics`
- `POST telematics/{id}/telemetry-webhook`, with optional `{ "rotate": true }`
- `POST telematics/{id}/telemetry-deliveries/{delivery}/replay`

The public receiver remains `POST webhooks/telematics/afaqy?telematic=...&key=...` under the application's API prefix. A new `device.telemetry_updated` broadcast identifies the device; an open device panel reloads its authorized resource and refetches after socket reconnection. Company broadcast identity comes from the persisted asset, not a worker session. Map movement uses the existing vehicle/trailer events, rejects older source timestamps, and reloads visible telemetry assets after socket reconnection with at most five concurrent requests.

Configure shared queues, retention, limits, and freshness under `telematics.telemetry` using `server/config/telemetry.php`. `TELEMATICS_BATCH_SIZE` controls units per ingestion delivery (default 100); `TELEMATICS_REQUEST_TIMEOUT_SECONDS` controls the bounded polling request timeout (default 45 seconds). AFAQY's adapter switches and optional queue overrides remain under `telematics.afaqy` in `server/config/afaqy.php`. Defaults: two MiB per webhook, 10,000 pending deliveries per connection, stale-position thresholds 120 seconds with ignition on and 600 seconds with ignition off/unknown. Retention (processed payloads 24 hours, quarantined deliveries seven days, device events 30 days, positions 90 days) is applied by `fleetops:prune-telematics-data` and is configured per company in Fleet-Ops → Settings → Telematics Data, with system defaults in the admin console; the config values are only the fallback. See `docs/TELEMETRY_ARCHITECTURE.md` § Retention. Each device's metadata records the effective threshold. Connection status categories remain unchanged.

## Incident verification

### September 2026 Docker investigation

The affected Docker deployment was investigated using live AFAQY responses and its real database, queue workers, and scheduler. The initial implementation's isolated tests had not exercised that deployment. The observed June-to-September gap was not a timezone conversion issue.

The investigation identified several independent failures:

- **Encryption configuration differed between services.** An application-only Compose override made the API and queue workers load different effective encryption keys. The API's established key successfully decrypted existing ledger and inspection records; that key was preserved and shared across services. Cached-token recovery remains necessary, but repeatedly refreshing tokens cannot repair inconsistent service configuration.
- **Event lookups scanned a large legacy table.** The deployment had approximately 50,000 event rows occupying 1.2 GB, with no index on `device_events.uuid`. Activity logging refreshes saved models by UUID, producing measured 10–13-second table scans. The UUID-index migration was applied; the measured UUID lookup then took about 7 ms. A subsequent real single-unit ingestion took approximately 1.7 seconds across 25 SQL statements, with about 249 ms spent in SQL. These are different measurements—a prior lookup versus a complete later ingestion—not a controlled whole-fleet speedup ratio.
- **Manual sync progress was invisible during ingestion.** The job fetched 381 units but retained queued/zero-page metadata while doing database work. It now persists running phases and fetched totals before ingestion, then checkpoints completed counters every 25 records or five seconds and at page boundaries. The manual 381-unit inventory sync completed after the database correction.
- **Some inventory records had no position.** The live response contained 381 units, of which 379 had valid positions and two lacked `last_update`. The polling worker now processes valid records independently and reports the other two as invalid; it does not discard their whole batches or count them as applied telemetry.
- **The request deadline was too short for an observed response.** One live Units List response took about 23.6 seconds, exceeding the former 20-second limit. The configurable 45-second request timeout remains constrained by the overall fetch budget.
- **Automatic processing was not running.** The rollout polling switch was disabled and the general development scheduler was stopped. Enabling it dispatched polling and unrelated scheduled workload. Investigation temporarily used a dedicated telemetry scheduler, four generic workers, and batches of 25. That experiment changed the deployment's worker topology and does not validate the user's single-worker baseline. The temporary services, dedicated queue settings, batch override, and queue reservation changes have since been removed. Configuration is restored to the existing default worker and ordinary application scheduler, retaining the shared established encryption key, enabled polling, schema index, and code corrections.
- **Scheduler interruption left a 24-hour overlap lock.** Recreating the scheduler during execution left its default overlap lease in the shared cache, preventing later polling dispatches even with empty worker queues. The two telemetry scheduled commands now use two-minute overlap leases, and the sync command's own lock also expires after two minutes. During recovery, only the confirmed abandoned telemetry mutex was cleared after stopping the scheduler; unrelated cache entries and locks were preserved.

During the temporary four-worker experiment, the initial eleven automatic sweeps drained 379 valid position records per sweep and explicitly reported the two records without positions as partial coverage. Those sweeps ranged from 22 to 65 seconds with a median of 36 seconds. A later sweep during scheduler recreation and heavy unrelated scheduled workload took 152 seconds; 22–65 seconds is therefore not the full observed range. The subsequent run stalled with retained inbox batches while the host was under heavy load. Its Docker VM had eight CPUs and about 8 GB of memory; the host reported about 16 GB of swap in use and a load average near 48. Pausing a separate stack did not immediately restore normal startup. A bounded startup probe measured about 12 seconds for Composer autoload and about 40 seconds for provider registration/boot, excluding ingestion. Temporary dedicated cron startup wrappers were also removed with that experiment. Recovery and repeated-cycle cadence on the restored single-worker baseline remain open verification gates.

These observations establish that the inspected 381-unit fleet was fetched and ingested during the investigation. They do not establish single-worker performance, a consistent sub-minute sweep, the 5,000-unit target, webhook throughput, or end-to-end browser latency. Actual AFAQY webhook delivery remains unverified.

### Verification checklist

1. Compare the same provider unit identifier across the live Units List record, persisted device, and attached asset. Compare raw UTC `dtt`/`dts` with `device.meta.telemetry`, `last_online_at`, and the asset's source timestamp.
2. Verify effective API/worker/scheduler configuration, not only the `.env` file. Compare keys securely without printing them; confirm the established key decrypts existing records before aligning services. Rebuild cached configuration and restart long-running processes after changes.
3. Check applied migrations and the actual query plan for UUID-based event lookup. Confirm the lookup uses an index instead of scanning historical event payloads.
4. Verify polling is enabled, the scheduler invokes both telemetry commands every minute, and workers consume the effective polling, ingestion, and broadcast queues. Watch pending/retry/processing delivery age and depth. After interruption, inspect the specific scheduler mutex and queued/reserved poll jobs before clearing anything. New scheduler leases expire after two minutes, but an old 24-hour lease already stored in cache keeps its original expiry; remove only a confirmed abandoned telemetry lock. Do not flush the shared cache or clear all scheduled-task locks.
5. Observe several complete runs. Reconcile fetched, applied, and invalid/failed counts; inspect quarantined records safely. A run with units lacking positions must remain explicitly partial.
6. Confirm the stored device and asset advance with newer source timestamps and verify their rendered state separately. Database ingestion success does not by itself validate browser/socket delivery.

## Cached-token decryption failures

A manual sync runs `SyncTelematicDevicesJob`; scheduled telemetry polling uses
`PollTelematicTelemetry`. Both authenticate through the AFAQY provider. Previously,
an unreadable encrypted access token in the shared cache raised Laravel's
`DecryptException` before the provider could refresh the token. This is a cache
recovery defect; a MAC error alone does not establish that deployment keys differ.

The provider now catches decryption failure only for its disposable cached token,
evicts that one entry under the existing account lock, and authenticates again
using the already-resolved connection credentials. A replacement token is cached
encrypted. Login failures and rate limits still propagate. Persisted credentials,
webhook secrets, and inbox payloads are not reset or treated as plaintext.
A warning with reason `cached_token_decryption_failed` identifies this path without
logging secrets. Deploy the patch and restart long-running queue workers before
retrying. If the exception remains, capture the calling stack frames without
arguments to identify which encrypted value failed; do not rotate APP_KEY or flush
the entire shared cache as a diagnostic step.

Regression coverage now uses Laravel's real encrypter (`illuminate/encryption` is
a development dependency), persisted encrypted credentials, and both manual and
scheduled job handlers. Tampered MACs and foreign-key cache entries fail before
the fix and recover afterward. Provider HTTP responses remain simulated; these
checks do not prove successful authentication against a live AFAQY account.

## Validation and acceptance gates

Run the new provider contract and database ingestion suites with the package Pest runner, and the provider-neutral telemetry browser tests with Ember:

```sh
php scripts/pest-runner.php server/tests/Unit/Support/Telematics/Providers/AfaqyRealtimeContractTest.php --debug
php scripts/pest-runner.php server/tests/Feature/Http/AfaqyRealtimeIngestionTest.php --debug
php scripts/pest-runner.php server/tests/Feature/Http/TelematicMixedFleetDeliveryTest.php --debug
php scripts/pest-runner.php server/tests/Feature/Http/Api/DeviceEventUuidIndexMigrationTest.php --debug
php scripts/pest-runner.php server/tests/SupportJobAndAiCoverageTest.php --filter='manual sync' --debug
node_modules/.bin/ember test --filter=telemetry
```

The incident patch passed 92 focused backend tests. The incident regressions exercise valid–invalid–valid polling batches through encrypted inbox processing and database ingestion, strict webhook quarantine, UUID-index migration behavior, and manual progress checkpoints/partial failures. The mixed-fleet SQLite fixture disables model listeners and external transports, and the manual-progress tests use provider/service doubles; those tests alone would not expose the deployed activity-log table scan. The live Docker investigation above supplies separate evidence for the actual MySQL schema and model path. Use `--debug` to retain PHPUnit test-pass events and its final exit status when the normal package formatter emits no summary.

The original implementation previously processed 5,000 signals plus 5,000 duplicate reconciliation observations in 45.9 seconds in the opt-in local SQLite benchmark (`XDEBUG_MODE=off AFAQY_RUN_LOAD_TESTS=1` with the ingestion suite above). During the provider-neutral refactor review, the final run took 66.5 seconds and failed the local 60-second budget; the original commit repeated in the same runtime took 83.4 seconds. Different machine/build load prevents a controlled speed comparison. The load gate remains open. It uses real model/database ingestion with indexed fixture tables, simulated spatial functions, and captured broadcasts; it excludes provider networking, broker latency, attached-asset load, and browser rendering.

The original implementation validation passed 103 selected backend tests, including database ingestion, sensor preservation, retention recovery, and provider contracts. A separate check against the installed Laravel dispatcher verified that broker failures release uniqueness leases. The standard browser launcher is blocked by the linked Testem/execa CommonJS/ES-module mismatch; the two focused browser tests passed all nine assertions in an isolated local harness with lazy engine assets and dummy host configuration. These cover live panel refresh, reconnect, freshness labels, map movement, and reversed broadcasts; they do not exercise a production socket server. PHPStan is not clean in the package-only runtime (unresolved Laravel helpers/model types and strict type findings); it is not counted as a passing gate.

The provider-neutral refactor passes 105 selected functional backend tests and five focused browser cases (19 assertions), including a second adapter with an unrelated payload format. See [the architecture review](TELEMETRY_ARCHITECTURE.md#review-and-validation) for the browser harness limits and current load-test results.

Use an isolated deployment with 5,000 linked test units and production-like database/cache/queue/broadcast workers for load acceptance. Replay the confirmed position envelope at 167 signals/second while polling all 5,000 units. Measure gateway acknowledgement p95, inbox receipt-to-processing p95, browser receipt-to-render p95, and queue depth through a burst, worker restart, and provider timeout/429 period.

Acceptance targets are a fully applied polling sweep within 60 seconds, webhook acknowledgement p95 below one second, receipt-to-visible-update p95 below five seconds, and bounded backlog that drains after disruption. Local contract tests validate five 1,000-unit pages; they are not proof of these deployment throughput or end-to-end latency targets. At 167 signals/second, 24-hour raw retention is about 14.4 million deliveries; size storage, retention, and cleanup capacity using actual encrypted payload sizes.

Device reporting frequency still depends on AFAQY/device configuration: approximately 30 seconds engine-on and five minutes engine-off. Fleetbase cannot display a position before the physical device reports it.

## Rollback

Set `AFAQY_WEBHOOKS_ENABLED=false` to disable push independently; retained deliveries remain available. Keep polling enabled for reconciliation. Set `AFAQY_POLLING_ENABLED=false` to return scheduled AFAQY polling to the existing discovery path. Do not roll back/drop inbox tables until retained deliveries have been processed or deliberately exported/discarded. Preserve the shared encryption key for replay.
