# Provider-neutral telemetry infrastructure

## Context and correction

Fleet-Ops consumes third-party telematics through `TelematicProviderRegistry`,
`TelematicProviderDescriptor`, and `TelematicProviderInterface`. Provider identity
belongs at registration and adapter boundaries. Commit 9f561352 incorrectly
extended AFAQY identity into shared resource components, map processing, and
persistence. The performance requirement did not justify those dependencies.

## Boundaries

- Keep the existing provider interface compatible. Add an optional telemetry
  interface for adapters opting into durable position ingestion and reconciliation.
- Adapters interpret external identities, payload envelopes, timestamps, sensors,
  authentication, rate limits, and reporting configuration. They return normalized
  device/event/sensor samples. Shared workers never inspect vendor payload fields.
- Use shared `telematic_sync_runs`, `telematic_deliveries`, and
  `telematic_webhook_credentials` tables, scoped by `telematic_uuid`; the existing
  connection identifies the provider and company. Do not create per-provider tables.
- Shared services implement durable receipt, retries, replay, retention, ordering,
  and diagnostics. Resolve adapters through the existing registry. Existing
  providers retain their existing path until they implement the optional interface.
- Declare UI capabilities in the existing provider descriptor. Shared connection
  components render a reusable telemetry panel only when that capability is present.
  Registration instructions and provider names are descriptor data, not template
  branches. A registry of provider-specific components is unnecessary for the
  currently identical diagnostics and registration UI.
- Devices expose a normalized `meta.telemetry` snapshot. Maps consume a normalized
  source timestamp from location events; neither knows which adapter produced it.
- Keep provider configuration and public webhook URLs compatible. The original
  migration has not been deployed or applied, as confirmed by the maintainer.
  Replace it directly with the generic migration; no compatibility tables, rename
  migration, or old job aliases are required. Existing pre-feature device metadata
  remains readable through its established position timestamp fallback.

## Review checks

1. No provider-name branches in shared frontend components or movement tracking.
2. Shared workers and ingestion accept a second registered adapter without edits.
3. Unsupported providers expose no new telemetry UI or receiver capabilities.
4. Storage, secret lookup, replay, and broadcasts remain connection/tenant scoped.
5. Fresh installs create only generic infrastructure tables; rollback preserves
   core device and integration tables.
6. Polling/push deduplication, UTC timing, partial updates, ordering, and recovery
   remain covered independently of the AFAQY adapter contract tests.

## Tradeoffs

Three shared tables separate run-level diagnostics, high-volume delivery retention,
and connection-level secrets with different lifetimes. Provider-specific protocol
classes remain appropriate. Capability opt-in avoids silently changing every
existing integration's ingestion or webhook behavior. Production throughput and
real AFAQY payload verification remain separate deployment gates.

## Adding an adapter

Implement `TelemetryProviderInterface` alongside the existing provider contract.
`telemetryUnits()` recognizes the adapter's inbound envelope; it must also accept
arrays of its raw samples for queued poll batches and failed-item retries.
`normalizeTelemetrySnapshot()` returns `device`, `event`, and `sensors` using the
existing normalization fields. `event.occurred_at` is device time,
`event.last_seen_at` is provider contact time, and
`event.meta.telemetry.provider_at` preserves the provider timestamp. No adapter
normalization method may authenticate or perform network I/O.

Register the driver with `TelematicProviderRegistry`, and declare
`metadata.telemetry.durable_ingestion`, `secure_webhooks`, and `reconciliation`
as appropriate. Optional `registration_instructions` and `contract_status`
control explanatory UI copy. `telemetryOptions()` supplies adapter overrides for
the shared `telematics.telemetry` configuration. Enabling an adapter does not
require new tables, jobs, frontend components, or map branches.

The example adapter in the database tests uses `tracker`, `measured`, `received`,
and `point` fields and a `signals` envelope. It exercises shared poll and webhook
processing, connection-scoped authentication, deduplication, and independent
freshness settings without inheriting the AFAQY adapter.


## Review and validation — 2026-09-16

The refactor was checked against the boundaries above, then exercised through a
second adapter. That feedback exposed numeric-cursor assumptions in the polling
worker, provider registration failures interrupting shared inbox maintenance, and
UI monitoring failing to start when capabilities arrived after the initial render.
The shared paths now handle opaque cursors, isolate unavailable registrations
while continuing recovery/retention, and restart monitoring on capability changes.

Selected backend checks cover existing provider contracts and lifecycle behavior,
database ingestion, poll/push deduplication, reversed delivery order, partial
sensor values, tenant scoping, recovery, and migration up/down. The second adapter
uses the same jobs, storage, receiver, and ingestion service without AFAQY
inheritance or authentication during normalization. Source checks find no AFAQY
identity in shared frontend components, map processing, telemetry workers,
telemetry controllers, or the shared ingestion service.

The location broadcast's additive `position_at` field is now asserted for a legacy
provider too. It allows every adapter's map updates to use source time. The
existing provider interface and legacy ingestion path remain available.

Current functional checks: 105 selected backend tests pass; PHP syntax, ESLint,
and template lint pass. The test-environment build succeeds. Five focused browser
cases pass all 19 assertions against that build. Browser cases ran in fresh Chrome
pages because the linked dummy host cannot recreate its universe registry across
rendering tests; the standard launcher also has a Testem/execa module mismatch.
These results do not establish a clean full browser suite or production socket
latency.

The opt-in local SQLite load gate remains open. The refactored path processed
5,000 signals plus 5,000 duplicate reconciliation observations in 66.5 seconds,
above its 60-second assertion. An earlier concurrent-build run took 109.2 seconds;
the original commit, loaded from a temporary checkout with the same dependencies,
took 83.4 seconds. Varying machine/build load prevents treating these runs as a
controlled speed comparison. Each run retained exactly 5,000 events. Do not claim
production throughput or the polling/webhook latency targets from these results.
