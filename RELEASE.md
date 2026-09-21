> v0.6.69 ~ "Fleet-Ops tools for Fleetbase AI"

---
## What's New
- **Fleet-Ops works with Fleetbase AI tool calling.** Creating orders from the AI prompt works again with the tool-calling assistant, and the assistant can also propose an optimized waypoint sequence for an order and explain what an order or resource import needs. Every change is a preview card the user confirms.
- **Fleet-Ops console actions.** The assistant can offer to open Fleet-Ops pages and dialogs, such as **Operations › Orders** and **New Order**, as confirmation cards.
- **Resource search for the assistant.** A `fleetops_search` tool finds orders, vehicles, drivers, work orders, maintenances, devices, sensors and telematics records by id, name, plate, VIN, email or phone.

---
## Fixes
- AI resource search no longer fails on every call with `Unknown column 'sensor_type'`, and database errors are no longer passed to the model.

---
## Testing
- Unit tests cover the new AI tools and console commands, and the AI capability registration.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
