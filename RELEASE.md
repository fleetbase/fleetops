> v0.6.67 ~ "Telematics reliability"

---
## What's New
- **Safee / DSCO syncs use a few batched requests.** Each sweep fetches every vehicle's current position, speed, heading and odometer in batches of up to 1,000 vehicles, with the vehicle list cached between sweeps. The old sync made three extra requests per vehicle; a 93-vehicle fleet now uses one request per minute instead of about 281. Temperature, door and history lookups stay out of routine polling.
- **Safee sign-in is reused and refreshed.** Access tokens are cached encrypted and refreshed before they expire, requests stay within Safee's 50-per-second account limit, and rate-limit responses are honoured.
- **Telematics can run on dedicated queue workers.** `TELEMATICS_POLL_QUEUE`, `TELEMATICS_INGESTION_QUEUE` and the new `TELEMATICS_BROADCAST_QUEUE` move polling, position processing and live-map broadcasts off the `default` queue for instances that need it. Nothing changes when they are unset. See `docs/TELEMATICS_QUEUES.md`.
- **Live telemetry status is easier to read.** Connection details show telemetry inside the page layout with a compact grid and collapsible setup and failure sections.

---
## Fixes
- Telematics syncs no longer fail with "SyncTelematicDevicesJob has been attempted too many times" for providers on bounded polling. Poll attempts finish within the queue's 90-second reservation, and older queued sync jobs hand off to it instead of running for up to an hour.
- A transient provider or TLS timeout no longer pauses scheduled polling for several minutes, and connections left in an error state keep being polled.
- Vehicles that have never reported a position no longer mark every sweep as partial, quarantine deliveries, or show the connection as degraded.
- Older or delayed samples cannot move a device or attached vehicle position backwards, and partial messages keep existing device identity and metadata.
- AFAQY recovers from unreadable cached tokens and shares one request deadline across sign-in and unit retrieval.
- Pausing Safee polling no longer queues failing sync jobs every minute.
- Device event lookups use a new index instead of full-table scans.
- The Radar dashboard widget links correctly when rendered outside the Fleet-Ops engine.

---
## Testing
- Backend tests cover Safee batching, token refresh, rate limits, ordered ingestion, tenant isolation, delivery checkpoints, paused providers, retry backoff and queue routing.
- A local run on a single default queue worker polled a 93-vehicle Safee fleet: 5 sweeps used 7 API requests and each completed with 91 positions applied and 2 vehicles without a fix.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
