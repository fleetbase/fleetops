# AGENTS.md — fleetops

## Repo purpose
The FleetOps TMS extension. Dual-structure:
- `server/` — Laravel package (`fleetbase/fleetops-api`)
- `addon/` — Ember addon (`@fleetbase/fleetops-engine`)

Both halves are linked into the host (`fleetbase/api` and `fleetbase/console` respectively) and ship together.

## What this repo owns
- `server/src/Models/` — Driver, Vehicle, Fleet, Order, Place, Zone, ServiceRate, etc.
- `server/src/Http/Controllers/` — FleetOps REST endpoints
- `server/database/migrations/` — FleetOps tables
- `server/routes/api.php` — route definitions registered under the `fleetops` namespace
- `addon/app/` — Ember engine source: routes, components, services
- `addon/extension.json` — extension registration metadata

## What this repo must not modify
- `core-api` source. If you need a new helper, propose it as a separate task in `core-api`.
- Other extensions (`storefront`, `pallet`, `ledger`).
- The host console's router or top-level layout.

## Framework conventions
- Server: Laravel 10+, PSR-4 under `Fleetbase\\FleetOps\\`
- Addon: Ember Engine via `ember-engines`, registered with `UniverseService` from `ember-core`
- Migrations: append-only, prefix with date

## Test / build commands
- Server: `vendor/bin/phpunit` inside `server/`
- Addon: `cd addon && pnpm test` (rarely needed; the host console picks up changes via hot reload)
- After server changes: `docker compose exec application php artisan migrate` and `php artisan route:list | grep fleetops`

## Known sharp edges
- **FleetOps is already auto-loaded** in this workspace via the `fleetbase/packages/fleetops` submodule. Linking the top-level `fleetops/` clone is only needed if you want to **edit** it. Until then, the bundled image's copy is what's running.
- The `addon/` and `server/` halves are versioned together — changing only one risks runtime drift.
- Live map requires `GOOGLE_MAPS_API_KEY` to function.

## Read first
- `~/fleetbase-project/docs/project-description.md`
- `~/fleetbase-project/docs/repo-map.md`
- `~/fleetbase-project/docs/ai-rules-laravel.md` (for `server/`)
- `~/fleetbase-project/docs/ai-rules-ember.md` (for `addon/`)
- `~/fleetbase-project/docs/ai-rules-workspace.md`
- `~/fleetbase-project/docs/extension-contracts.md`

## Boost gate
Before first edit in `server/`: `composer require laravel/boost --dev && php artisan boost:install`, then commit.
