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
> v0.6.70 ~ "Order reporting and a cleaner default dashboard"

---
## What's New
- **Report on orders, their items and tracking.** The report builder can answer questions like "Which products sold the most this month?" and "What did orders total this month?".
  - Orders expose their ID and Internal ID, and relationships for tracking (tracking number, region, latest status), Order Config, Customer Vendor, Created By, Purchase Rate › Service Quote and Payload Return.
  - `Payload` exposes its items with name, SKU, price, dimensions, metadata and destination. Select an item column for one row per item, or group by one to summarise per product.
  - Summary columns count orders distinctly, so they stay correct when item rows are selected. Distance, duration and transaction amount have totals and averages.
  - `meta` has no fixed shape, so no column assumes a key in it. Read keys with a computed column, for example `CAST(JSON_UNQUOTE(JSON_EXTRACT(payload.entities.meta, '$.quantity')) AS DECIMAL(15,2))`.
  - Column labels drop the table name ("Type", not "Order Type"). Distance and duration are labelled in meters and seconds, and money columns are marked "(minor units)".
- **A cleaner default dashboard.** Fleet-Ops widgets declare their place on the default dashboard: Radar first, then Active Orders and Drivers Online, a full-width Live Fleet map, and Revenue Trend, Top Drivers and Maintenance side by side.
- **Live Fleet widget cards match the live map.** Clicking a driver or vehicle on the widget map shows the same card as **Operations › Live Map**.

---
## Fixes
- Custom field values on fuel reports and service areas are saved. They were dropped because the save hooks read the snake_case payload root, while the console sends `fuelReport` and `serviceArea`.
- The report schema no longer references 13 columns and join keys that don't exist: driver name, email and phone and the driver's vehicle, the vehicle's driver, and the fuel report cost, odometer and date. Transaction summaries sum `transaction.amount`.
- Every report table, and the item join, leaves soft-deleted rows out on core-api v1.6.64 and later.

---
## Dependencies
- Order items, JSON totals and soft deletes in reports need `fleetbase/core-api` v1.6.64. The schema still registers on older versions.
- The report builder and the default dashboard order ship in `@fleetbase/ember-ui` v0.4.4.

---
## Testing
- Every declared report column and join key was checked against the database schema, and order reports were run against real storefront orders on MySQL in strict mode.
- Tests cover the report schema at 100% line coverage, the Live Fleet map cards and the default dashboard order.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
