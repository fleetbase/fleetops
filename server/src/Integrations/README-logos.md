# Integrated Vendor Logo Assets

`ResolvedIntegratedVendor::logo()` resolves logos via
`Utils::assetFromFleetbase('integrated-vendors/' . $code . '.png')`,
which serves them out of the Ember host app's `public/images/integrated-vendors/`
directory. Filenames must be lowercase and match the registry `code` field exactly.

## Expected assets for Phase 1 + Phase 2

| Code | Expected path (in ember host) | Status |
|---|---|---|
| `lalamove` | `public/images/integrated-vendors/lalamove.png` | (existing — not added by this PR chain) |
| `parcelpath` | `public/images/integrated-vendors/parcelpath.png` | **pending** — referenced by Phase 1 Task 6 registry entry and by Phase 1 Task 10 onboarding panel and Task 11 rate comparison UI |
| `ups` | `public/images/integrated-vendors/ups.png` | **pending** — referenced by Phase 2 Task 17 UPS registry entry and by Phase 1 Task 10 onboarding panel |
| `usps` | `public/images/integrated-vendors/usps.png` | **pending** — referenced by Phase 2 Task 17 USPS registry entry and by Phase 1 Task 10 onboarding panel |

Until the actual PNG files are added, the IV form and rate comparison
UI will render broken-image icons for these providers. The logic is
not blocked — every `ResolvedIntegratedVendor` still resolves a logo
URL; the URL just happens to 404 until the assets land.

## Sourcing

UPS and USPS have strict brand usage guidelines. Any logo added must
be sourced from the carrier's official brand assets page and reviewed
against their usage terms before merging:

- UPS: https://about.ups.com/us/en/our-company/our-brand.html
- USPS: https://about.usps.com/newsroom/photography/

ParcelPath is a third-party rate broker and should use the logo
provided by their branding team.

This file is documentation only — it has no runtime effect.
