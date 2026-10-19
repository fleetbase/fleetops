> v0.6.74 ~ "Settings-backed geocoding everywhere"

---
## Highlights

- **Fix: the console geocoder endpoints return results.** `int/v1/geocoder/query` and `int/v1/geocoder/reverse` returned `[]` for valid addresses whenever the Google Maps API key was set in the console instead of in the environment. This broke address lookup in the coordinates and autocomplete inputs, for example when editing a place. Both endpoints now use the same geocoder as `places/lookup`. (#408, fleetbase/fleetbase#723)
- **Fix: every other geocoding path uses the settings-backed key.** Place creation from an address or coordinates, place search, address-only place creation on the public API, and driver tracking locality all go through the same geocoder. Failed lookups are now logged instead of hidden. (#409)
- **Fix: `config/geocoder.php` passes the region rather than the locale to Google Maps, and uses the default cache store.** (#409)

---
## Upgrading
- No migrations or commands.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
