# Dispatch workspace: server API (track D1)

Server changes that let a dispatcher plan a day, review it, publish it and
manage the routes once they are running. In the console a Manifest is called a
**Route**; this note uses "manifest" for the record and "route" for what a
driver drives.

All internal endpoints are under `/int/v1/fleet-ops/`. Dates are `Y-m-d` and
are checked strictly (`2026-02-30` and `tomorrow` are refused with 422). Times
(`publish_at`) are ISO 8601.

## Manifest lifecycle

```
draft ──publish──▶ published ──driver works a stop──▶ active ──last stop done──▶ completed
  └──────────────── cancelled (any status before completed) ◀──────────────────────┘
```

| Status | Meaning | Driver sees it |
| --- | --- | --- |
| `draft` | Committed by the Orchestrator, dispatcher only | No |
| `published` | Released to the driver; `published_at` set | Yes |
| `active` | The driver arrived at, completed or skipped a stop | Yes |
| `completed` | Every stop completed or skipped | Yes |
| `cancelled` | Called off | Only if it had been published |
| `in_progress` | Written by older builds once a route started; read as `active` | Yes |

New columns on `manifests`: `published_at`, `publish_at` (a publish booked for
later). Migration `2026_11_27_000001_add_dispatch_workspace_lifecycle`.

**Existing manifests are left as they are.** Every manifest committed before
this change is a `draft`, so after deploying, drivers no longer see those
routes until a dispatcher publishes them. There is no record of which drafts a
dispatcher meant a driver to run, so the migration does not guess.

### Driver (Navigator) endpoints

`GET /v1/drivers/{id}/manifests`, `GET /v1/manifests/{id}`,
`GET /v1/manifests/{id}/route`, `POST /v1/manifests/{id}/optimize` and
`PATCH /v1/manifest-stops/{id}` only see manifests a driver may see (table
above). A draft answers 404, as if it did not exist.

### Publish

`POST manifests/{id}/publish` — body `{ publish_at? }`.
Without `publish_at`, or with a time already past, it publishes now. With a
future time it stores `publish_at` and queues `PublishScheduledManifest` for
that time; booking again replaces the time (the earlier job does nothing).
200 `{ manifest }`; 422 when the manifest is not a draft.

`POST manifests/publish` — body `{ ids?: [public ids], scheduled_date?, publish_at? }`.
With `ids`, those manifests; otherwise every draft of `scheduled_date`.
200 `{ published: [ids], scheduled: [ids], skipped: [{ id, status }] }`.

Publishing tells the driver:
- push `manifest_published` (`{ type, id, scheduled_date }`) — `Notifications\ManifestPublished`
- broadcast `manifest.updated` with `change: "published"` on `driver.{public_id}`, `driver.{uuid}`, `company.{uuid}`
- the per-order `order.driver_assigned` notices older Navigator builds load orders from (queued, `NotifyBulkAssignedDriver`)

## Orchestrator

### `POST orchestrator/run`

New and changed request fields:

| Field | |
| --- | --- |
| `order_ids` | Plan these orders. |
| `scheduled_date` | Without `order_ids`: plan the orders whose `scheduled_at` is on this day. |
| `order_statuses` | Now honoured: any of `created`, `dispatched`, `started` (all three when omitted). Anything else is 422. |

One of `order_ids` or `scheduled_date` is required (422 otherwise). A run no
longer falls back to every unassigned order on every date.

New response fields:

```json
{
  "assignments": [...], "unassigned": ["order_x"], "summary": {...},
  "unassigned_reasons": { "order_x": { "reason": "no_available_vehicle", "message": "No vehicle was available for this order." } },
  "plan_run_id": "plan_...",
  "scheduled_date": "2026-11-28"
}
```

`unassigned_reasons` has an entry for every id in `unassigned` (an empty map is
`{}`). Codes: `no_available_vehicle`, `no_available_driver`,
`no_matching_driver`, `no_vehicle_assigned`, `capacity_exceeded`,
`missing_required_skills`, `max_tasks_exceeded`, `not_feasible`,
`invalid_order` (the message says what is missing), `not_placed`. An order an
engine did not explain gets that engine's usual cause. See
`Orchestration\Support\UnassignedReasons`.

Each run is stored in `orchestration_runs` (scope, mode, engine, assignments,
unassigned, reasons, summary, `committed_at`). The driver-assignment engine
now reports unplaced **orders** (it used to list vehicle ids) with reasons.

### `POST orchestrator/commit`

New request fields:

| Field | |
| --- | --- |
| `scheduled_date` | The day the routes are for (today when omitted). Manifests are dated by it, and each order's `scheduled_at` moves to that day, keeping its time of day; an order with no time takes its planned arrival when that is on the day, else the start of the day. |
| `plan_run_id` | Marks that run committed. |
| `publish` | `true` publishes the new routes at once. |
| `publish_at` | Books the publish for that time instead. |

Manifests are created as drafts and the order assignments are saved without
telling drivers; drivers hear about them on publish. An order already on
another open route leaves it first (that route closes up and its driver, if
the route was published, gets `manifest.updated` `stop_removed`).

New response fields: `scheduled_date`, `published` (ids), `scheduled` (ids).

The current Orchestrator workbench sends `publish: true`, so its routes keep
reaching drivers as soon as they are committed.

### `POST orchestrator/import-orders`

Request: `rows`, plus `validate_only` (check, create nothing) and
`import_batch_id` (join an earlier batch when re-submitting fixed rows; a new
uuid otherwise).

Response:

```json
{
  "import_batch_id": "…",
  "validate_only": false,
  "rows": [
    { "row": 3, "status": "created", "order_id": "order_…" },
    { "row": 4, "status": "failed", "reason": "priority must be a whole number.", "errors": { "priority": "…" } },
    { "row": 5, "status": "valid" }
  ],
  "summary": { "created": 1, "failed": 1, "valid": 0 },
  "created": ["order_…"],
  "failed": [{ "row": 4, "error": "…" }]
}
```

`row` is the row's `_rowIndex`, or its position in `rows`. Every row gets an
outcome, in the order sent. Rows of one multi-waypoint order (same
`order_ref`) share an outcome: one bad row fails the whole order, and the
others say "Another row of this order is invalid." Checked: a drop-off
(street, or coordinates), `order_type`, dates (`scheduled_at`,
`time_window_start/end`), whole numbers (`priority`, `parcels`), numbers
(weights, volume, COD, entity values), coordinate ranges, and
`customer_type` / `facilitator_type`. A row with drop-off coordinates and no
street now gets its drop-off (it used to be dropped silently).

Created orders carry `meta.imported_via_orchestrator: true` and
`meta.import_batch_id`. `created` and `failed` are kept for older clients.

## Reassignment

`Support\Dispatch\RouteReassignment` is the one way an order changes driver.
It moves the order's unfinished stops off its old route (which re-numbers),
onto the new driver's open route for the order's day at a position (or the
end), sets the order's driver, vehicle (the route's, else the driver's) and
manifest, and saves the order so the observer sends `order.driver_unassigned`
to the old driver and `order.driver_assigned` to the new one. A route the
driver can see also broadcasts `manifest.updated` (`stop_removed`,
`stop_added`, `resequenced`).

Rules: completed and skipped stops stay on the route they were driven on; a
stop is never placed before a finished stop; with no route for the new
driver that day the order leaves its route and becomes unrouted; coming off a
draft the order goes to the new driver's draft, otherwise to the route the
driver can see.

Entry points that now go through it:

| Entry point | Behaviour |
| --- | --- |
| `PUT orders/{id}` (order detail) | After the save, a routed order follows its new driver (`followDriverChange`). |
| `PATCH orders/bulk-assign-driver` | Each order is reassigned (silently; the bulk job still sends the new driver's notices). Orders already with that driver are skipped. |
| `PATCH orders/schedule` (Scheduler drag) | A new driver, or a new day for a routed order, reassigns; a time change on the same day stays a quiet save. |
| `orchestrator/commit` (plan overrides) | Orders leave their previous route before joining the new one. |

The public `v1` order update does not follow routes yet.

## Insert into a route

`POST manifests/{id}/stops` — body `{ order: public id or uuid, position?: 1-based }`.
Inserts the order as a stop, re-numbering the route. Without `position` the
order goes where it adds the least distance (append when it has no
coordinates). A position before a finished stop moves to just after it. The
order leaves any route it was on. 200 `{ manifest, order, position }`; 422 for
a completed or cancelled route, a completed or canceled order, or a bad
position.

`GET manifests/best-fit?order={id}&scheduled_date=` — the cheapest insertion
on each driver's open route that day (default: the order's scheduled day, then
today), cheapest first, one entry per driver:

```json
{ "order": "order_…", "scheduled_date": "2026-11-28", "candidates": [
  { "manifest_id": "manifest_…", "driver_id": "driver_…", "driver_name": "…", "vehicle_id": "vehicle_…",
    "status": "active", "position": 3, "extra_distance_m": 1250, "extra_duration_s": 140,
    "extra_km": 1.25, "extra_minutes": 2.3, "stop_count": 12 }
] }
```

The detour is measured from the driver's last position (else the last
finished stop) with the same straight-line estimate the built-in sequencer
uses, so it ranks routes rather than promising an ETA. 422 when the order has
no drop-off coordinates.

## Permissions

| Endpoint | Permission |
| --- | --- |
| `manifests/{id}/publish`, `manifests/publish` | `fleet-ops dispatch order` |
| `manifests/{id}/stops` | `fleet-ops assign-driver-for order` |
| `manifests/best-fit` | `fleet-ops list order` |
