# Safee / DSCO live polling

## Problem and API contract

The legacy sync fetched inventory and current state, then made three serial
requests per vehicle (`last-info`, `positions`, and `events`) before applying a
second ingestion pass. A 93-vehicle fleet required 281 data requests, excluding
authentication. Each enrichment request could wait up to 120 seconds. Historical
samples could overwrite the latest position, and failed connections were excluded
from subsequent scheduled discovery.

Safee's [Tracking REST Service V2.2.4.0](https://www.safee.com/_docs_/Safee_Tracking_REST_Service.pdf)
documents:

- `POST /api/v2/vehicle/list-info` with an empty JSON object for inventory.
- `POST /api/v2/vehicle/last-state` with `live: true`, `endDate: null`, and a
  `vehicles` ID array to retrieve current state, position, speed, heading, and
  available counters.
- Up to 1,000 vehicle IDs per request, and 50 requests per user per second.
- Unix timestamps with fractional seconds. The response envelope's `time` is not
  a vehicle's source timestamp.
- OAuth access-token expiry and refresh-token grants.

There are no position webhooks for this integration. Temperature/door enrichment
and historical position/event retrieval remain outside routine live polling.
Existing enrichment APIs remain available for explicit use.

## Polling and ingestion

Safee opts into the existing provider-neutral telemetry pipeline. No Safee tables,
UI branches, dedicated queues, or additional workers are introduced.

1. The minute scheduler coalesces queued/running polling and pending ingestion.
   Enabled connections remain eligible after transient errors. When
   `SAFEE_POLLING_ENABLED=false`, Safee is paused; it does not fall back to the
   legacy monolithic discovery job.
2. Inventory is cached briefly. Manual discovery refreshes it. Pagination uses
   stable inventory slices; `list-info` itself is not treated as a paginated API.
3. Each slice retrieves current states in one batch of at most 1,000 IDs. Missing
   states are reported per unit; malformed, duplicate, or unsuccessful responses
   cannot masquerade as successful empty fleets.
4. Raw batches enter the shared encrypted durable inbox. Existing default-queue
   jobs apply them transactionally with per-device serialization, deduplication,
   and source-time ordering. Older samples cannot move current device or attached
   asset positions backwards.
5. Units without a valid fix (for example source date `0`) keep their device link
   and are counted in `invalid_count`, but a polled delivery is not quarantined
   and its run is not marked partial for them. Pushed deliveries keep quarantine.
6. Partial messages preserve attachment identity and existing metadata, counters,
   and sensors. A zero source date is treated as missing data. Source timestamps
   are converted to UTC; receipt time is never substituted for a missing GPS time.

A fresh fleet sweep uses `1 + ceil(N / 1000)` data requests; subsequent sweeps use
`ceil(N / 1000)` while inventory is cached. These are request-count improvements,
not guarantees about provider latency or application throughput.

Authentication is cached using credential-sensitive keys and encrypted tokens.
Expiry is honored, a rejected token may be refreshed once, and authentication,
manual syncs, and retries share the per-user request budget. HTTP429 respects
`Retry-After`; connection/server failures use bounded queue retry delays. Scheduled
poll retries wait at most 60 seconds (15, 30, 60, 60) so a transient login or TLS
timeout cannot suppress minute polling for several minutes; manual requests keep
15, 60, 180, 300 seconds. Previously queued legacy discovery jobs exit quietly when
polling is paused or already queued instead of marking the connection as errored.

## Configuration and rollout

Safee polling is enabled by default because it replaces the previous scheduled
sync. `SAFEE_POLLING_ENABLED=false` pauses Safee polling/manual batch sync.
`meta.telemetry_sync_enabled=false` or a disabled connection also prevents work.
Webhooks remain unsupported.

- `SAFEE_INVENTORY_CACHE_SECONDS`: default300, capped at300seconds.
- `SAFEE_REQUEST_TIMEOUT_SECONDS`: default30seconds, capped by the remaining sweep
  budget. Authentication and data requests share that budget.
- Existing `TELEMATICS_POLL_QUEUE` and `TELEMATICS_INGESTION_QUEUE` default to
  `default`. One existing queue worker can process both types of jobs.
  To run telematics on dedicated workers for one instance, see
  [Dedicated telematics queue workers](TELEMATICS_QUEUES.md).
- Poll attempts use an80-second hard timeout and a60-second cooperative sweep
  budget, below the baseline Redis `retry_after=90` seconds.

Apply the existing generic telemetry migrations and device-event lookup index,
then reload long-running workers so they use the new provider class. Clear/rebuild
cached configuration as part of the application's normal deployment process.
Do not retry an old failed monolithic job to recover a connection; start a new
manual sync or let scheduled polling recover it.

Inspect the shared telemetry diagnostics for fetched/applied/failed counts,
incomplete sweeps, pending deliveries, queue delay, and source age. Manual sync
completion is tied to its ingestion run; queueing or fetching alone is not
reported as a completed sync.

## Validation boundaries

The local DSCO connection had93 stored units. A read-only API probe on
2026-09-17 authenticated in2.466seconds, listed all93 units in4.136seconds,
and fetched all93 current states in3.932seconds. The returned access token
expired after300seconds. Some units returned source date0; these are invalid
positions and must remain visible as partial data rather than fabricated fixes.

The immediately preceding legacy worker failure was an SSL connection timeout
at the login endpoint. Subsequent authenticated requests succeeded. This shows
an intermittent provider/network failure as well as the independently verified
request-volume, token-refresh, and scheduler-recovery defects.

Contract and database tests use controlled fixtures; they do not prove production
throughput. Production freshness remains bounded by Safee/device reporting,
provider response time, the minute polling interval, and available capacity of
the shared worker. Monitor queue growth with the rest of the application's jobs.

### Local default-worker run (2026-09-17, 06:40-06:46 UTC)

After restarting the single existing `queue:work` worker on the local stack, five
scheduled sweeps of the 93-unit DSCO connection made 7 Safee requests in total:
1 token, 1 `list-info`, and 5 `last-state`. Every run completed with 93 units,
91 applied, 2 counted as invalid, one processed delivery, no Safee failed jobs, and
9-15 seconds from run creation to processed delivery. The legacy design would have
made about 281 data requests per sweep.

Only 42 of the 91 positioned units had a source fix under five minutes old (median
age 386 seconds). This reflects when devices last reported; faster polling cannot
improve it. AFAQY polling on the same worker hit 44-second `units/lists` timeouts
during this window, and those jobs share that worker's capacity. This run is local
evidence, not production acceptance.
