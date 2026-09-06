> v0.6.63 ~ "Fleet and driver relationship display fixes"

---
## Bug Fixes
- **Driver vendor names display correctly.** The Drivers table now uses the `vendor_name` value included in the list response instead of reading an unloaded `vendor` relationship.
- **Fleet pages load hierarchy relationships correctly.** Internal Fleet-Ops requests now use the Fleet model's Eloquent relationship names, preventing the `RelationNotFoundException` raised when opening the Fleets route.

---
## Testing
- Added a regression assertion for the vendor column used by the Drivers table.
- Added route coverage for the relationship names requested by the Fleet index, edit and details pages.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
