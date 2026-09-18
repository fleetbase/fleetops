> v0.6.68 ~ "Telematics sync status and SASCO fuel"

---
## What's New
- **SASCO is a native fuel provider.** Connect a SASCO B2B account alongside PetroApp to import fuel transactions, with sandbox and production environments, amounts in SAR, driver name and phone, and receipt images. Transactions are matched to vehicles by plate.
- **"Sync Devices" works while telematics polling is running.** A manual sync requested while a scheduled sweep is queued or running is recorded and completed by the next sweep, instead of failing with "already queued or running".
- **Telematics connection status stays current.** Each completed scheduled sweep updates the connection status and last sync time, so a connection that has recovered no longer shows "Needs attention" or an old last sync date.

---
## Fixes
- Manual telematics sync requests can no longer stay queued or failed indefinitely after their job is lost; the next scheduled sweep completes them.
- Fuel provider environment labels and sync run details no longer refer to PetroApp for other providers.

---
## Testing
- Backend tests cover manual syncs during scheduled sweeps, stale failure recovery, partial sweeps, and adoption of abandoned requests.
- SASCO provider tests cover login, token caching, re-login after a 401, connection tests, pagination limits, error handling and transaction normalization.
- `docs/TELEMATICS_QUEUES.md` now documents that dedicated telematics workers must share the queue worker's image and `APP_KEY`, with verification commands and troubleshooting.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
