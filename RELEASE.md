> v0.6.70 ~ "Telematics data retention"

---
## What's New
- **Telematics Data settings.** A new Fleet-Ops → Settings → Telematics Data page lets each organization decide how long telemetry is kept: device events, raw event payloads, positions, processed and quarantined delivery envelopes, and sync run diagnostics. Administrators set system-wide defaults from the admin console (Fleet-Ops Config → Telematics Data). A value of 0 keeps rows forever.
- **Scheduled retention sweep.** `fleetops:prune-telematics-data` runs every fifteen minutes and applies each organization's policy in bounded batches, so a large backlog is drained gradually without stalling ingestion. Defaults: events 30 days, raw payloads stripped after 7 days, positions 90 days, processed deliveries 24 hours, quarantined deliveries 7 days, sync runs 7 days.
- **Storage usage and on-demand cleanup.** The settings page shows rows, oldest row and estimated size per table for your organization, and "Run cleanup now" queues an immediate retention run.

---
## Fixes
- Device events no longer store the raw provider unit twice. `payload` keeps the raw unit; `meta` holds only the normalized block (speed, heading, odometer, ignition, fuel level, timing). New event rows are roughly half the size.
- Telemetry-driven saves (device, event, position, vehicle, sensors) no longer write to the activity log on every poll. Organizations that want them can enable "Log telemetry activity" in Telematics Data settings. Manual edits are logged as before.
- Delivery inbox and sync run retention moved from the drain command into the retention sweep; `fleetops:drain-telematic-inbox` now only recovers deliveries and finishes interrupted runs.

---
## Upgrade notes
- New indexes on `device_events (company_uuid, created_at)`, `positions (company_uuid, created_at)` and `telematic_sync_runs (telematic_uuid, updated_at)`; on very large tables run `php artisan migrate` in a maintenance window.
- Retention is enabled by default. Existing rows older than the defaults are removed over successive runs after upgrading; set the values to 0 before upgrading if you need to keep history indefinitely.
- Deleting rows frees space inside the database files, not on disk. Run `OPTIMIZE TABLE` off-peak to return space to the operating system.
- Run `php artisan fleetbase:create-permissions` to register the `telematics-settings` permission and the Telematics Settings Manager policy and role.

---
## Testing
- Backend tests cover the retention policy layering and clamping, the prune command (per-company policies, compaction, inbox tables via connections, orphans, batch caps, dry runs, filters, locking), the settings and storage usage endpoints, the index migrations, the on-demand job, and the meta and activity-log changes to ingestion.
- Ember tests cover the settings route guard, the settings controller, and the admin defaults component.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
