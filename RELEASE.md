> v0.6.66 ~ "Inspections"

---
## What's New
- **Inspection forms are built from typed fields.** A form is groups of fields laid out on a grid: pass/fail checks with severity and on-fail settings, text, numbers, selections, dates, photo uploads and signatures. Forms are built and published from a form builder in the console.
- **One inspection sheet everywhere.** The same sheet is used to fill in an inspection in the console, to read one back, and on a public link. A failed check opens its severity, unsafe flag, comment and photos in place, and a defects tray summarises what needs attention. It works on phones and tablets.
- **Drivers file inspections through the API.** The `v1` inspection endpoints list published forms, file an inspection against one, and read back submissions and a vehicle's history, for the Navigator app and other integrations.
- **Inspection links for anyone in the organisation.** A link can be assigned to any user, protected by a six-digit PIN, and emailed or texted to them. A failed inspection can raise an issue and open a work order, and every submission records who filed it.

---
## Fixes
- Place, zone and service area details, and the place and point map modals, no longer fail to render with "A resolved helper cannot be passed as a named argument".

---
## Testing
- Model and controller contract tests cover inspection forms, submissions, links and the PIN lockout.
- The Fleetbase Postman collection documents the `v1` inspection endpoints.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
