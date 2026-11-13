> v0.7.0 ~ "Laravel 12 and core-api 2.0"

---
## Highlights

- **Laravel 12.** The server package now runs on Laravel 12 with Carbon 3 and requires `fleetbase/core-api` ^2.0. It installs into the Laravel 12 Fleetbase API (fleetbase/fleetbase v0.8.0); Laravel 10 installs stay on the v0.6.75 line.
- **No security advisory exceptions.** `composer audit` reports no advisories on the Laravel 12 dependency set, so the Composer advisory workarounds are gone.
- **Order config policies and triggers.** An order config now decides more of how its orders behave, for any kind of operation.
  - **Policies:**
    - how adhoc orders are offered (strategy, offer time, radius, and what happens when nobody accepts);
    - driver matching by vehicle type, and leaving out busy drivers;
    - what customers may do: payment methods, a required quote, settling earlier orders first, cancelling, chat, rating and sharing tracking;
    - pricing an order again from the actual distance and time.
  - **Triggers:** actions that run when an order reaches an activity, is created, completed or canceled, when no driver accepts, or when the driver comes close to the pickup or drop-off. The built-in actions notify the customer or driver, open or close the customer chat, set meta, or cancel. Extensions can add their own, for example Ledger's `send_invoice`.
  - Both are edited in the Console's new **Policies** and **Triggers** tabs, and are returned with the public order config.
- **Order config templates.** Make a config from a template in the Console (**New Order Config**) or with `php artisan fleetops:create-order-config {template} --company=`. The templates are `transport`, the default, and `passenger`, for on-demand trips.
- **Adhoc dispatch strategies.** Adhoc orders can be offered to every nearby driver at once, or one driver at a time with a timed offer (the default), with decline and expiry. The company default is set under Settings → Dispatch, and a config or an order can override it.
- **Customer API.** These endpoints work for any kind of customer:
  - places, sign-in codes and phone changes;
  - for an order: dispatch, cancel, driver, tracker and ETA;
  - chat with the driver, driver ratings, sharing a tracking link, and account deletion.

  The order's config decides which of them a customer may use.
- **Configs enforce what they declare.** The public API now applies the config's strict transitions, proof-of-delivery requirement, dispatch setting and initial status, as the Console already did. Orders created through the API accept `custom_fields`, and flow conditions can read the config's custom fields. The flow condition operators `in`, `has`, `contains`, `beginsWith` and `endsWith` now work as their names say.
- fix(request): call core-api's inputArray instead of array (#423)
- chore(l12): keep fresh-install columns identical without Doctrine (#422)

---
## Upgrading

- **Requires fleetbase/core-api v2.0.0** (Laravel 12, PHP 8.2 or later). Upgrade the API to fleetbase/fleetbase v0.8.0 first, then this extension.
- Run `php artisan migrate` after upgrading.
- **Flow conditions:** a config whose flow `logic` used `in`, `has`, `contains`, `beginsWith` or `endsWith` now gets the documented result. Before this release those operators compared the wrong way round, or never matched.
