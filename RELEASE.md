> v0.6.71 ~ "Extensions can add columns, actions and buttons to Fleet-Ops tables and panels"

---
## Highlights

- **Resource view registries.** Extensions can add the following through `fleet-ops:table:<resource>:<slot>` and `fleet-ops:details:<resource>:<slot>`:
  - columns, row actions, bulk actions and toolbar buttons on every top-level Fleet-Ops table;
  - header buttons and "…" menu items on its details panels and side panels.

  Built-in items carry stable ids, so registered items can be placed before or after them.
- **Fix: registered details tabs.** Opening a driver, vehicle or trailer side panel removed the route from tabs other extensions had registered, which broke them in the full details view. Side panels also left out the tabs they could render.
- **Fix: the sensor details view showed the tabs registered for places.**

---
## Upgrading
Needs fleetbase/ember-core v0.3.25 and fleetbase/ember-ui v0.4.5.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
