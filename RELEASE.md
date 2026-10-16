> v0.6.73 ~ "Test-mode driver creation and manifest tenant isolation"

---
## Highlights

- **Fix: creating a driver or contact with a test API key returned 500.** Copying the account into the sandbox database copied live-only columns (such as billing's `companies.resource_units`) and failed with `Unknown column`. Only the columns the sandbox tables have are copied now. (#404)
- **Fix: the proof-of-delivery dashboard widget loads.** It requested `int/v1/fleet-ops/proofs/recent`, which does not exist, so it always showed "There is nothing to see here". It now requests `int/v1/proofs/recent`, where the route from #388 lives. (#407)
- **Fix: manifest lookups are scoped to the caller's organization.** Manifests, manifest stops and drivers resolved by identifier on the public API and in the console are now constrained to the current company; a missing company session resolves nothing. (#403)

---
## Upgrading
- No migrations or commands.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
