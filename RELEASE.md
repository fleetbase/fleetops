> v0.6.64 ~ "Order responses no longer fail when a vehicle is assigned"

---
## Bug Fixes
- **Orders with an assigned vehicle serialize again when expansions are requested.** The Vehicle and Fleet resources read the request's `with` list and loaded it onto the model they wrap. That is correct on their own endpoints, where the controller has already allowlisted the list, but both resources are also rendered nested inside Order and Driver responses. There the same request carries the parent's expansions, so `GET /v1/orders?with=payload` on an order with a vehicle assigned failed during serialisation with `RelationNotFoundException: Call to undefined relationship [payload] on model Vehicle`. Navigator order fetches and the console scheduler both hit this. Nested resources now keep only the relations the wrapped model actually defines; direct endpoint behaviour is unchanged.

---
## Testing
- Added resource-level regressions for parent expansions reaching a nested Vehicle or Fleet resource, for string and empty `with` forms, and for resources wrapping plain data.
- Added end-to-end checks resolving a real vehicle and a real fleet against an order-shaped request.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
