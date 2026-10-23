> v0.6.75 ~ "Safer integrations and tenant-isolated profile accounts"

---
## Highlights

- **Security: a profile never links to another organization's login account.** Creating a driver, customer or contact with an email or phone that belongs to another organization's account linked that account and exposed its name, email and phone. The new organization could then reset its password or deactivate it. Only accounts that already belong to the organization, or belong to no organization, are reused now. The refusal does not say whose account it is. `POST /v1/drivers` also ignores a `company` that is not the API credential's own. Logins already shared by the old behaviour can only be managed by their holder. (#414)
- **Fix: orders can be edited with a partial payload.** `PUT /v1/orders/{id}` accepts a `payload` with only the parts that change, such as only entities or only a new dropoff. (#411)
- **Fix: addresses sent with coordinates keep their street.** A place given as a street address plus `latitude`/`longitude` was reverse-geocoded and stored as a different nearby address. The street and the coordinates are now kept as sent, without a geocoding call. (#411)
- **Feature: idempotent order creation.** `POST /v1/orders` accepts an `Idempotency-Key` header. A retry with the same key within 24 hours returns the order already created, with `Idempotent-Replayed: true`, and a concurrent duplicate gets `409`. (#412)
- **Fix: `scheduled_at` keeps its UTC offset.** `2026-10-21T11:00:00-07:00` is now stored as 18:00 UTC instead of 11:00, and `PUT /v1/orders/{id}/schedule` stores the UTC instant of a date and time in the company timezone. Rows written before this keep their old values. (#412)
- **Fix: service quotes.** The `currency` filter works again, stored quotes expire after 48 hours by default (`fleetops.service_quotes.ttl_minutes`), and `preview=1` prices a request without storing quote rows. (#412)
- **Fix: tracking numbers with a space can be looked up again.** Since v0.6.72, a number such as `AL 7796486490AE`, generated for a company whose name has a space in its first three letters, got "not found". Single inner spaces are accepted now, and new numbers only use letters and digits in their prefix. (#413)

---
## Upgrading
- No migrations or commands.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
