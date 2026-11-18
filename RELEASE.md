> v0.7.2 ~ "Foreign keys point at unique keys, and Passenger for everyone"

---
## Highlights

- **Fleet-Ops tables that foreign keys point at can be altered on MySQL 8.4 with `restrict_fk_on_non_standard_key` on.** MySQL 8.4 refuses any change to a table that a foreign key points at through a non-unique key, with `1553 Cannot drop index '<unknown key name>'`. v0.7.1 worked around this for one migration. This release removes the cause: `uuid` is now unique on `devices`, `inspection_forms`, `inspection_submissions`, `order_configs`, `telematics`, `warranties` and `work_orders`. Each column's old index is replaced, so it is not indexed twice. A column that already has a unique or primary key is left alone. A table with duplicate values is skipped and reported, and the migration can be run again once they are removed. Runs on the main and sandbox databases. Storefront's foreign keys to `order_configs` are covered too.
- **Every organization gets the Passenger order config.** It sits next to **Transport**, so the Passenger app works without running `fleetops:create-order-config passenger` first. New organizations get it when they're created. The migration `2026_11_18_000001_create_passenger_order_configs` adds it to every existing organization; one that already made it with the command keeps the config it has. Like Transport, it can't be deleted and its flow is read-only, while its policies and triggers can be edited.

---
## Upgrading

- Run `php artisan migrate` and `php artisan sandbox:migrate`. If a table is reported as having duplicate values, it is skipped. Remove the duplicates, then run the `php artisan migrate:refresh --force --path=…` command the message gives. It rolls back and re-runs only that migration, and lists every other migration as "Migration not found" without touching them.
- No new dependency: the migration doesn't need core-api 2.0.2's migration hook.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
