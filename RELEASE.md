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
