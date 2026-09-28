# Dedicated telematics queue workers

By default, all telematics work runs on the `default` queue alongside every other
background job. On an instance with a large fleet, that work can crowd out
notifications, order events, and other jobs. This guide moves telematics work to
its own queues so dedicated worker containers process it while the existing
worker keeps serving `default`.

This is an **opt-in, per-instance** setup. With no environment variables set,
nothing changes: every job and broadcast stays on the same queue as before.

## What each setting routes

| Setting | Default | Work routed |
| --- | --- | --- |
| `TELEMATICS_POLL_QUEUE` | `default` | Scheduled and manual polls (`PollTelematicTelemetry`), legacy device discovery (`SyncTelematicDevicesJob`), and queued connection tests (`TestTelematicConnectionJob`). |
| `TELEMATICS_INGESTION_QUEUE` | `default` | Applying polled or pushed positions (`ProcessTelematicDelivery`), including jobs re-queued by `fleetops:drain-telematic-inbox` and manual replays, and on-demand retention runs (`PruneTelematicsDataJob`, queued by "Run cleanup now" in Settings → Telematics Data). |
| `TELEMATICS_BROADCAST_QUEUE` | unset | Live-map broadcasts created by telematics ingestion: `DeviceTelemetryUpdated`, and `VehicleLocationChanged` / `TrailerLocationChanged` when a device is attached. When unset, broadcasts use the queue connection's default queue. |

`TELEMATICS_BROADCAST_QUEUE` affects only broadcasts that telematics ingestion
creates. Vehicle and trailer location broadcasts from the driver app and public API
remain on the default queue.

Broadcasts can outnumber sync jobs. Each ingested unit with a newer position or
contact time queues one device broadcast, plus one vehicle or trailer broadcast
when attached. A fleet that reports every minute can queue hundreds of broadcast
jobs per minute, so route broadcasts as well as polling and ingestion.

## Before you start: check which sync path the provider uses

Separate workers keep telematics from blocking other jobs. They do not make a slow
sync faster, and they do not fix an unsafe job.

- **Bounded polling path** (Safee scheduled and manual syncs; AFAQY scheduled polls
  with `AFAQY_POLLING_ENABLED=true`): poll attempts are limited to an 80-second
  hard timeout and a 60-second sweep budget, and ingestion yields every 40 seconds. These jobs are safe for the stock Redis
  `retry_after` of 90 seconds and for multiple concurrent workers.
- **Legacy discovery path** (other providers, AFAQY manual syncs, and AFAQY scheduled
  syncs while polling is disabled):
  `SyncTelematicDevicesJob` allows a 3,600-second run while Redis `retry_after`
  is 90 seconds. A run lasting over 90 seconds can be handed to another worker and
  end with `SyncTelematicDevicesJob has been attempted too many times`. Adding
  workers increases the likelihood that another worker picks it up. Where possible,
  move the provider to the bounded polling path, for example by setting
  `AFAQY_POLLING_ENABLED=true` for AFAQY connections.

## Setup

Start the workers **before** setting the queue variables. Jobs sent to a queue with
no worker wait indefinitely.

### 1. Add telematics workers

Each telematics worker must be an exact copy of the existing `queue` worker: the
same image and tag, the same `env_file`, `environment`, volumes and `depends_on`,
including every Compose override file that changes `queue`. Change only the command.
Workers running a different build, or missing `APP_KEY`, fail every telematics job.

Docker Compose example, assuming `queue` is defined in `docker-compose.yml` and any
image or environment overrides for it live in `docker-compose.override.yml`:

```yaml
# docker-compose.yml: copy the full `queue` service definition, then change the command.
services:
  telematics-queue:
    # image, env_file, environment, volumes, depends_on: identical to `queue`
    command: ["php", "artisan", "queue:work", "--queue=telematics", "--sleep=1", "--max-time=3600"]
    restart: unless-stopped
    deploy:
      replicas: 2

  telematics-broadcast-queue:
    # image, env_file, environment, volumes, depends_on: identical to `queue`
    command: ["php", "artisan", "queue:work", "--queue=telematics-broadcasts", "--sleep=1", "--max-time=3600"]
    restart: unless-stopped
```

```yaml
# docker-compose.override.yml: repeat every override that applies to `queue`.
services:
  telematics-queue:
    image: "fleetbase-api:latest"   # the same image as `queue`
  telematics-broadcast-queue:
    image: "fleetbase-api:latest"
```

Notes:

- **Use the same image as `queue`, not a separate `fleetbase/fleetbase-api:latest`.**
  Docker does not download a newer `latest` when a copy already exists locally, so a
  new service can start from an old cached image. If you do use a registry image,
  pin a version tag and pull it for every API container together.
- **Every API container needs the same `APP_KEY`**: application, scheduler, the
  default worker and the telematics workers. Provider credentials, token caches and
  telematics deliveries are encrypted with it. If only the application service
  receives it (for example through a mounted `.env` or an override), put it in a
  shared `env_file` instead. Never generate a new key for a worker.
- **Check the merged configuration, including when using `extends`.** Run
  `docker compose config` and compare the resolved `image`, `env_file` and
  `environment` of `queue` and each telematics service before deploying.
- The `--queue` names must match the variables in step 2 exactly. A worker listening
  on `telematics-broadcast` while broadcasts go to `telematics-broadcasts` receives
  nothing.
- Leave the existing `queue` service unchanged. `queue:work` without `--queue`
  processes the connection's default queue (`REDIS_QUEUE`, normally `default`).
- Leave `--timeout` at its default (60 seconds) or set any value below the Redis
  connection's `retry_after` (90 seconds). Polls (80 seconds) and ingestion
  (60 seconds) define their own timeouts, which override the worker setting.
  Broadcast jobs use the worker value.
- `--max-time` recycles long-running workers so they pick up deployments and release
  memory. `restart: unless-stopped` starts them again.
- On Kubernetes, ECS, or another platform, create equivalent deployments with the
  same image, environment, and commands.

### 2. Set the queue variables for this instance only

Add these to the environment of the application, scheduler, **and every queue
worker** container. The scheduler dispatches polls and the application dispatches
manual syncs, so every process must agree on the queue names:

```dotenv
TELEMATICS_POLL_QUEUE=telematics
TELEMATICS_INGESTION_QUEUE=telematics
TELEMATICS_BROADCAST_QUEUE=telematics-broadcasts
```

To use one worker type for all telematics work, set all three to `telematics` and
omit the broadcast worker.

AFAQY polls and ingestion also read `AFAQY_POLL_QUEUE` and `AFAQY_INGESTION_QUEUE`.
Those take precedence over the `TELEMATICS_*` values when set, so remove or align
them. Legacy discovery and connection-test jobs always use `TELEMATICS_POLL_QUEUE`.

### 3. Deploy the change

1. Deploy the new worker containers and confirm they are running.
2. Apply the environment variables to the application, scheduler, and workers.
3. If configuration is cached, rebuild it with `php artisan config:cache` in each
   container, or through the normal image/deploy process.
4. Restart long-running processes so they read the new configuration:
   `php artisan queue:restart`, then reload Octane (`php artisan octane:reload`)
   if the application runs under Octane.

### 4. Existing backlog on `default`

Jobs already on `default` stay there, and the existing worker must process them.
The new setting affects only newly dispatched jobs.

- Do not run `php artisan queue:clear` on `default`. It deletes every pending job,
  including non-telematics work.
- For opted-in batch providers such as Safee, a queued `SyncTelematicDevicesJob`
  hands off to bounded polling and exits within seconds.
- Queued legacy discovery jobs for other providers can each occupy the default
  worker for a long time. If the backlog does not drain, inspect those specific
  jobs rather than clearing the entire queue.

## Sizing

Start with **2 telematics workers and 1 broadcast worker**, then adjust based on
queue depth.

Current work per connection:

- One poll per connection per minute, coalesced while its previous sweep is still
  ingesting. Safee requests at most 1,000 vehicle IDs per `last-state` call.
- Up to `ceil(units / TELEMATICS_BATCH_SIZE)` ingestion jobs per sweep (default
  100 units per job). A fleet of about 470 reporting devices is about 5 ingestion
  jobs per minute.
- Workers can process different connections, deliveries, and devices concurrently.
  Per-connection and per-device locks prevent duplicate polling or out-of-order
  position updates.

For reference, a local single-worker run for 93 Safee units applied each delivery in
about 5-8 seconds (about 55-85 ms per unit), including database work. This was a
development machine, not production. Measure production queue depth rather than
extrapolating this value.

Additional workers do not increase provider limits. Safee connections share a limit
of 50 requests per second per account across all workers.

## Verification

First confirm every API container runs the same build and application key. Each line
must show the same key fingerprint (a hash, never the key) and Fleet-Ops version:

```bash
for c in $(docker ps --format '{{.Names}}' | grep -E 'application|scheduler|queue'); do echo "== $c $(docker inspect --format '{{.Config.Image}}' $c)"; docker exec $c php artisan tinker --execute="echo config('app.key') ? substr(hash('sha256', config('app.key')), 0, 12) : 'MISSING', ' ', json_decode(file_get_contents('vendor/fleetbase/fleetops-api/composer.json'))->version, PHP_EOL;"; done
```

Confirm Laravel loaded the queue names in the application and a telematics worker:

```bash
php artisan config:show telematics.telemetry
```

Queue depth should stay close to zero between minute ticks:

```bash
php artisan queue:monitor redis:default,redis:telematics,redis:telematics-broadcasts --max=500
```

Also verify:

- `telematic_sync_runs` for the connection shows a new `completed` run about once
  per minute. `incomplete` means the fetch failed and will retry.
- The worker logs show `PollTelematicTelemetry` and `ProcessTelematicDelivery` only
  on the telematics workers, and broadcast jobs only on the broadcast worker.
- Other jobs on `default`, such as notifications and order events, start promptly
  again.
- The connection's status and "Last sync" update after each completed scheduled
  sweep. Clicking "Sync Devices" while a sweep is queued or running records the
  request, and the next sweep completes it.

## Troubleshooting

| Symptom | Cause |
| --- | --- |
| Telematics worker logs show `MissingAppKeyException` | The worker has no `APP_KEY`. Give every API container the same key and recreate the workers. |
| A worker reports a different Fleet-Ops version | The worker uses a different or stale cached image. Use the `queue` service's image. |
| Broadcast jobs are never processed | The broadcast worker's `--queue` name does not match `TELEMATICS_BROADCAST_QUEUE`. |
| Scheduled sync commands report `Queued 0 telematics sync job(s)` for an enabled connection | A poll job failed on a worker that could not load it, leaving its one-hour uniqueness lock. It expires on its own; after fixing the workers you can release it with `Cache::lock('laravel_unique_job:Fleetbase\\FleetOps\\Jobs\\PollTelematicTelemetry' . $telematicUuid)->forceRelease()`. |

## Rollback

1. Remove the three variables from the application, scheduler, and workers, rebuild
   cached configuration, and restart processes as in setup step 3.
2. Keep the telematics workers running until `telematics` and
   `telematics-broadcasts` are empty. Jobs remaining on those queues are not moved
   back to `default`.
3. Remove the worker containers.
