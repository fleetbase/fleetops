> v0.6.61 ~ "A grid view for drivers, a sequenceable order flow, and two silent API no-ops closed"

---
## Highlights
Two API defects in this release were silent: both answered `200` and neither did what the caller asked. A vehicle odometer update was discarded, and a driver-scoped list came back unscoped. Alongside them, the order config flow now publishes enough of its shape to be sequenced, and Drivers Management gains a card layout.

---
## Features
- **Drivers Management has a card view.** A layout toggle switches the index between the table and a card grid, and the choice is remembered across visits.
- **The order config flow publishes its graph.** `activities`, `sequence` and `logic` now ride along with each activity, so a consumer can order the flow and offer a next step instead of rendering an unordered set. Transitions are normalised to a list of codes regardless of which of the two stored shapes a flow was authored in.

---
## Bug Fixes
- **`PUT /v1/vehicles/{id}` silently discarded the odometer.** The field is fillable on the model but was missing from the controller's input projection, so a driver app recording mileage received a `200` and a correct-looking body while the reading was dropped. `odometer` and `odometer_unit` are now accepted and validated.
- **Issue and fuel-report lists scoped by `driver_uuid` came back scoped by nothing.** The base filter silently ignores a query parameter it cannot match to a method, so the filter was dropped and the response was narrowed only by company — every driver's records, with no sign the request had been narrowed at all. `driver_uuid`, `driver_assigned` and `vehicle_uuid` are now recognised on both filters.
- **Leaflet marker icons are served from the leaflet package** rather than resolving to a broken URL.

---
## Testing
- Coverage held at 100% across the vehicle input projection, both driver-scoped filters, and the order config flow projection.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
