> v0.6.72 ~ "Customer tracking page settings, safer public tracking and structured label codes"

---
## Highlights

- **Tracking Page settings.** A new Fleet-Ops → Settings → Tracking Page lets each organization configure the customer tracking page:
  - its own page at `/t/{slug}`, with a live address check;
  - whether its numbers can be tracked on the shared page;
  - branding: display name, accent colour with a live contrast check, an optional dark-mode accent, support contacts, "Powered by Fleetbase", and languages;
  - how customers verify (text message or email codes, session length, customer sign-in);
  - what verified customers see.

  Administrators set the shared page's defaults under Fleet-Ops Config → Tracking Page. The new tracking pages themselves arrive in a later release; these settings are what they will read.
- **Safer public order lookup.** `GET int/v1/fleet-ops/lookup` is rate limited per address and returns a minimal resource: tracking number, status, timeline, ETA, stop coordinates and items. It no longer returns notes, meta, internal ids, files, rates or customer details. The driver's location is included only while the order is under way. Unknown and malformed tracking numbers get the same answer.
- **Structured label codes.** Tracking-number QR codes now carry a tracking URL holding the tracking number and the owner's public id, which a phone camera opens as the tracking page. Barcodes use Code 128. Every scan endpoint still accepts the uuid codes already printed on parcels.
- **Fix: signatures on private S3 buckets.** Signatures were not stored on buckets that reject ACLs; they are now written without one and served through signed URLs. Legacy absolute avatar URLs on vehicles, drivers and places are re-signed when read.
- **Fix: the customer credentials email** linked to an unrouted page; it now links to `/customer-access/{slug}`.
- **Fix: parcel box images** on the service rate form and details showed broken images in production builds.

---
## Upgrading
- Run `php artisan fleetbase:create-permissions` to register the new `tracking-page-settings` permission.
- Private-bucket signature storage pairs with fleetbase/core-api's `File::signStoredUrl()`; on older core-api releases stored avatar URLs pass through unchanged.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
