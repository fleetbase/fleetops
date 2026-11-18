> v0.7.1 ~ "The adhoc dispatch migration runs on MySQL 8.4 and after a failed run"

---
## Highlights

- **The v0.7.0 migration now runs on MySQL 8.4.** MySQL 8.4 turns on `restrict_fk_on_non_standard_key` by default and then rejects any change to a table whose foreign keys point at a non-unique key, with `1553 Cannot drop index '<unknown key name>': needed in a foreign key constraint`. `order_configs.uuid` is one of those, so `2026_11_06_000001_adhoc_dispatch_and_order_config_policies` failed there. Its column changes now run with that setting off for the session, and nothing changes on MySQL 8.0, MariaDB or SQLite.
- **A failed run of that migration no longer blocks the next one.** MySQL doesn't roll back schema changes, so the tables and columns made before the failure stayed, and running it again failed on "table already exists". Each table and column is now created only if it's missing.

---
## Upgrading
If a v0.7.0 upgrade stopped in this migration, upgrade to v0.7.1 and run `php artisan migrate` again. It finishes from where it stopped. Run `php artisan sandbox:migrate` too if you use sandbox.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
