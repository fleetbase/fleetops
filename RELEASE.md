> v0.6.71 ~ "Extensible tables and panels, configured order lifecycles and order presentation profiles"

---
## Highlights

- **Resource view registries.** Extensions can add the following through `fleet-ops:<resource>:table:<slot>` and `fleet-ops:<resource>:details:<slot>`:
  - columns, row actions, bulk actions and toolbar buttons on every top-level Fleet-Ops table;
  - header buttons and "…" menu items on its details panels and side panels.

  Built-in items carry stable ids, so registered items can be placed before or after them.
- **Configured order lifecycles.** An order config can define its own lifecycle in `meta.lifecycle`: `initial`, `completed`, `canceled` and `terminal` activities, `dispatch: false` and `strict_transitions`. The internal API starts orders at the initial activity, refuses to dispatch when dispatch is off, accepts only the flow's transitions when strict, and cancels to the configured activity. Configs without a lifecycle behave as before.
  - **Lifecycle tab** in Order Configuration, to view and edit a config's lifecycle.
  - **The board** shows a configured lifecycle's activities as its columns.
  - **The activity flow editor** roots at the configured initial activity, and handles flows with cycles.
- **Order presentation profiles.** An extension can give its order type its own order form sections, extra and hidden fields, details view sections and hidden actions, registered in `fleet-ops:order-presentation`. **Edit details** opens the profiled form. Other order types are unchanged.
- **The Order Configuration manager lists every config**, including configs created while the console is open.
- **An order's activity timeline follows status changes made on the server.**
- **Fix: the Activity Flow crashed on a flow with a cycle.**
- **Fix: order details now load the assigned vehicle with the order.**
- **Fix: the order form failed when its custom fields couldn't load.**
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
