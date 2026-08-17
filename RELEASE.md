> v0.6.60 ~ "Closes an authentication bypass, and clears a run of API 500s"

---
## Highlights
A security fix and a broad sweep of public API defects surfaced by running the official Postman collection against a live stack. Several endpoints answered `500` where a `404` or `422` belonged, and a few were unreachable entirely.

---
## Security
- **Closed a verify-code authentication bypass in the driver flow.** Please upgrade.
- The non-production verification-code bypass is now scoped to explicitly designated review accounts, so a bypass code alone is not enough — the identity has to be on the allowlist too.

---
## Bug Fixes
- **Driver `register-device` was unreachable on both driver routes.** Laravel never injects a class-typed parameter that declares a default, so the injected request was always null.
- **Geofence driver history asked for a UUID the API never issues.** It now resolves the driver by the public id callers actually hold.
- **`/from-qr` returned a 500**, and the QR code's content is now published in debug mode so the flow can be exercised.
- **Fuel reports could not be created without a location**, and could not be updated.
- **A sensor could not be created at all** — `last_position` had no default.
- **Customer signup with a place failed** — the Place location now defaults.
- Unknown onboard organization answers `404` instead of `500`.
- Duplicate part SKU and fuel transaction answer `422` instead of `500`.
- Restored the vehicle maintenance schedule workflows.

---
## Testing
- Coverage restored to 100% across the QR, geofence, driver auth, customer request and navigator changes.

---
## Continuous Integration
- The server, Ember and Postman workflows now run on `dev-v*` release branches.
- The contract run tests this branch's API code rather than the published package.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
