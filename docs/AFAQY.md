# AFAQY live telemetry

## Behavior

AFAQY uses the provider-neutral telemetry infrastructure described in [TELEMETRY_ARCHITECTURE.md](TELEMETRY_ARCHITECTURE.md). Its adapter owns authentication and payload normalization; shared services own persistence, ingestion, and diagnostics.

The existing AFAQY integration already used Units List. The new path requests 1,000 units per page with `simplify: 0` and the documented `basic` / `last_update` projection groups. It fetches all pages, places batches of 100 units in an encrypted durable inbox, and ingests them asynchronously. A connection in transient `error` status remains eligible; `disabled` connections and connections with `meta.telemetry_sync_enabled: false` do not.

Position webhooks use the same ingestion path and do not authenticate against AFAQY. The adapter provisionally accepts a unit object, a unit array, or a `data` envelope containing either, including `_id` with nested `data.last_update`. This is an assumption, not a verified AFAQY webhook contract. Unsupported authenticated JSON is quarantined and can be replayed after an adapter correction. Vehicle event webhooks and Signals history are outside this implementation.

`dtt` orders positions. `dts` records last contact, falling back to `dtt`. UTC receipt and processing times are recorded separately. Old signals can enter history but cannot replace newer device, vehicle, or trailer positions. Equal-time samples keep the first current position. Duplicate signals do not create another event or broadcast. `active` is not treated as connectivity.

## Stage 1 deployment

1. Apply the Fleet-Ops migration `2026_09_15_000001_create_telematic_telemetry_tables`. It adds shared `telematic_deliveries`, `telematic_sync_runs`, and `telematic_webhook_credentials` tables for any registered telemetry adapter. It also adds a composite device lookup index; existing telemetry data is preserved.
2. Use a shared cache with distributed locks (Redis recommended) across all scheduler and worker instances. An array cache is suitable only for isolated tests. Cached login tokens are encrypted, scoped to account/host/password, and reused for up to 29 days; a credential change selects a new cache namespace. All API attempts for the same host/account share a rolling 60-request/minute budget.
3. Set the following environment variables and rebuild the application's configuration cache:

   ```dotenv
   AFAQY_POLLING_ENABLED=true
   AFAQY_WEBHOOKS_ENABLED=false
   AFAQY_POLL_QUEUE=afaqy-poll
   AFAQY_INGESTION_QUEUE=afaqy-ingest
   ```

4. Provision workers for both queues. For example:

   ```sh
   php artisan queue:work --queue=afaqy-poll --timeout=120 --tries=5
   php artisan queue:work --queue=afaqy-ingest --timeout=60 --tries=1
   ```

   Set the queue connection's `retry_after` above 150 seconds (for example 180). Run multiple ingestion workers, and retain workers for the application's broadcast/default queue. Queue names default to `default` if not configured, for compatibility; dedicated workers prevent discovery or unrelated jobs from delaying telemetry.

5. Confirm the application scheduler executes every minute. It runs both `fleetops:sync-telematics` and `fleetops:drain-telematic-inbox`. The drain recovers accepted deliveries if broker dispatch failed or a worker stopped; no payload is acknowledged before durable persistence.
6. Open the connection's live telemetry panel. Verify a full polling run transitions from fetching to ingesting to completed, with expected unit/page/applied counts. `incomplete` and `partial` are failures to investigate, not complete fleet coverage.

Rate-limit retries honor `Retry-After` (bounded to one hour). Other polling failures use 15/60/180/300-second backoff, with five queue attempts. Poll HTTP requests have 20-second response and five-second connection limits. Sweeps stop at a 90-second fetch budget or 100 pages and report incomplete coverage. Pending poll ingestion coalesces later polling ticks. Failed ingestion retries only failed units; the original encrypted body remains available. Five failed/interrupted attempts quarantine the delivery.

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

Configure shared queues, retention, limits, and freshness under `telematics.telemetry` using `server/config/telemetry.php`. AFAQY's adapter switches and optional queue overrides remain under `telematics.afaqy` in `server/config/afaqy.php`. Defaults: two MiB per webhook, 10,000 pending deliveries per connection, processed payload retention 24 hours, quarantine retention seven days, stale-position thresholds 120 seconds with ignition on and 600 seconds with ignition off/unknown. Each device's metadata records the effective threshold. Connection status categories remain unchanged.

## Incident verification

For the screenshot's vehicle, compare the same AFAQY unit ID/IMEI and attached Fleetbase asset. Fetch its Units List record and compare raw UTC `dtt`/`dts` with `device.meta.telemetry`, `last_online_at`, and `vehicle.telematics.last_event_at`. Then inspect polling-run status, oldest pending delivery, worker/broadcast queue delay, and the open browser panel. A June-versus-September date gap cannot be accounted for by UTC+3 alone. No affected production account was accessed during implementation.

## Validation and acceptance gates

Run the new provider contract and database ingestion suites with the package Pest runner, and the provider-neutral telemetry browser tests with Ember:

```sh
php scripts/pest-runner.php server/tests/Unit/Support/Telematics/Providers/AfaqyRealtimeContractTest.php
php scripts/pest-runner.php server/tests/Feature/Http/AfaqyRealtimeIngestionTest.php
node_modules/.bin/ember test --filter=telemetry
```

The original implementation previously processed 5,000 signals plus 5,000 duplicate reconciliation observations in 45.9 seconds in the opt-in local SQLite benchmark (`XDEBUG_MODE=off AFAQY_RUN_LOAD_TESTS=1` with the ingestion suite above). During the provider-neutral refactor review, the final run took 66.5 seconds and failed the local 60-second budget; the original commit repeated in the same runtime took 83.4 seconds. Different machine/build load prevents a controlled speed comparison. The load gate remains open. It uses real model/database ingestion with indexed fixture tables, simulated spatial functions, and captured broadcasts; it excludes provider networking, broker latency, attached-asset load, and browser rendering.

The original implementation validation passed 103 selected backend tests, including database ingestion, sensor preservation, retention recovery, and provider contracts. A separate check against the installed Laravel dispatcher verified that broker failures release uniqueness leases. The standard browser launcher is blocked by the linked Testem/execa CommonJS/ES-module mismatch; the two focused browser tests passed all nine assertions in an isolated local harness with lazy engine assets and dummy host configuration. These cover live panel refresh, reconnect, freshness labels, map movement, and reversed broadcasts; they do not exercise a production socket server. PHPStan is not clean in the package-only runtime (unresolved Laravel helpers/model types and strict type findings); it is not counted as a passing gate.

The provider-neutral refactor passes 105 selected functional backend tests and five focused browser cases (19 assertions), including a second adapter with an unrelated payload format. See [the architecture review](TELEMETRY_ARCHITECTURE.md#review-and-validation) for the browser harness limits and current load-test results.

Use an isolated deployment with 5,000 linked test units and production-like database/cache/queue/broadcast workers for load acceptance. Replay the confirmed position envelope at 167 signals/second while polling all 5,000 units. Measure gateway acknowledgement p95, inbox receipt-to-processing p95, browser receipt-to-render p95, and queue depth through a burst, worker restart, and provider timeout/429 period.

Acceptance targets are a fully applied polling sweep within 60 seconds, webhook acknowledgement p95 below one second, receipt-to-visible-update p95 below five seconds, and bounded backlog that drains after disruption. Local contract tests validate five 1,000-unit pages; they are not proof of these deployment throughput or end-to-end latency targets. At 167 signals/second, 24-hour raw retention is about 14.4 million deliveries; size storage, retention, and cleanup capacity using actual encrypted payload sizes.

Device reporting frequency still depends on AFAQY/device configuration: approximately 30 seconds engine-on and five minutes engine-off. Fleetbase cannot display a position before the physical device reports it.

## Rollback

Set `AFAQY_WEBHOOKS_ENABLED=false` to disable push independently; retained deliveries remain available. Keep polling enabled for reconciliation. Set `AFAQY_POLLING_ENABLED=false` to return scheduled AFAQY polling to the existing discovery path. Do not roll back/drop inbox tables until retained deliveries have been processed or deliberately exported/discarded. Preserve the shared encryption key for replay.
