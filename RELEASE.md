> v0.6.62 ~ "The public Fleet, Vehicle and Driver APIs now expose what the records actually hold"

---
## Highlights
The public v1 API exposed a small subset of what the Fleet, Vehicle and Driver records can hold, and the gaps were silent: a caller sending a field the controller did not copy received a `200` and a response body that looked correct while the value was discarded. This release closes that gap, adds fleet hierarchies and fleet membership to the public API, and makes it possible to record a driver who has no email address or phone number.

---
## Features
- **Fleet create and update accept every safe field.** `name`, `color`, `task`, `status`, and the `service_area`, `zone`, `vendor` and `parent_fleet` relationships. Only `name` and `service_area` were reachable before, so a fleet hierarchy could not be built through the API at all. `"parent_fleet": null` clears a parent; a fleet may not be its own parent, nor sit beneath one of its own descendants.
- **Fleet membership has public endpoints.** `POST` and `DELETE` on `/v1/fleets/{fleet}/vehicles/{vehicle}` and `/v1/fleets/{fleet}/drivers/{driver}`, all four taking public IDs and sharing one response shape. Assignment is idempotent and restores a soft-deleted membership rather than duplicating it; removal is a safe no-op and touches only the pivot, never the driver, the vehicle, the driver's current vehicle, or any other fleet.
- **The Vehicle contract covers the whole record.** The input projection accepted 21 of the model's 99 fields; it now accepts all 90 safe ones — identity, odometer and measurement, body, capacity and dimensions, lifecycle and financing, regulatory and engine specifications, structured `specs`/`details`/`meta`, and orchestrator constraints — each with type-appropriate validation. `vendor`, `category`, `warranty` and `photo` resolve from public IDs.
- **A driver can be recorded without credentials.** `email` and `phone` are optional on create; an operational record may legitimately have neither. Nothing is invented to fill the gap and no invitation is sent when there is nowhere to send one. Such a driver cannot sign in to Navigator until credentials are supplied.
- **Relationships are readable back as public IDs.** A caller that writes `parent_fleet: "fleet_abc"` can now read the assignment back. Asking for a relation through `?with=` still returns the nested object it always did, and internal console responses are unchanged.

---
## Bug Fixes
- **`Driver::$fillable` listed `'meta,'`** — a trailing comma inside the string — so driver metadata was never mass assignable and never persisted through the public API.
- **Driver input was a blocklist, not an allowlist.** Anything nobody had thought to exclude reached `Driver::create()` intact, including `auth_token`, `user_uuid` and `company_uuid`, while `location`, `heading`, `altitude`, `speed` and `meta` were dropped on every write.
- **Driver photo upload wrote `photo_uuid` to `users`,** which has no such column. `User` guards mass assignment by fillable, so every photo uploaded through the public API was discarded without a word. It now writes `avatar_uuid`.
- **A partial vehicle update took the vehicle offline.** The create-time `online` default was applied on update too, so a plate correction or an odometer reading silently reset it.
- **Relationship filters could never match.** `?vendor=`, `?fleet=`, `?driver=` and the fleet hierarchy filters compared caller-supplied public IDs against uuid columns. `FleetFilter::query()` searched a `user` relation Fleet does not have, `DriverFilter::phone()` a `phone` relation that does not exist, and `FleetFilter::zone()` a `zone_uuid` column `zones` does not have — the last three raised rather than filtered.
- **`VehicleFilter` had no `internal_id` filter,** which is the lookup an importer keys on to decide whether a vehicle already exists.
- **Cross-company relationship IDs were accepted and silently dropped.** Relationship inputs were validated with unscoped `exists` rules, so another organization's public ID passed validation and then resolved to nothing. They are now scoped at validation and again at resolution, and a cross-company ID is answered exactly as a missing one — so a response cannot be used to probe another organization's data.
- **The Fleet resource ran four `count()` queries per fleet on every public request** and discarded the results, because `when()` evaluates a plain value argument eagerly.
- **The Fleet webhook payload gated `parent_fleet` on `serviceArea`,** so a subfleet with no service area never reported its parent.

---
## Testing
- Coverage held at 100% on all three metrics — 34670/34670 statements, 4428/4428 methods, 530/530 classes.
- New database-backed coverage of the fleet membership pivots: idempotent assignment, restore of a soft-deleted membership, repeated removal as a no-op, and preservation of the driver's vehicle and of unrelated fleet memberships.
- Data-driven parity tests prove every newly exposed Fleet, Vehicle and Driver field is accepted and persisted, and that tenancy, authentication and generated columns still are not.

---
## Continuous Integration
- The PHP, Ember and Postman contract workflows now run on pull requests targeting `release/v*`. `release.yml` learned that branch name in v0.6.61's follow-up, but the three check workflows did not — so a PR retargeted onto a release branch ran no checks at all.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
