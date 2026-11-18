> v0.7.1 ~ "The adhoc dispatch migration can be re-run"

---
## Highlights

- **A failed run of the adhoc dispatch migration no longer blocks the next one.** MySQL doesn't roll back schema changes, so when `2026_11_06_000001_adhoc_dispatch_and_order_config_policies` stopped partway, the tables and columns it had already made stayed, and running it again failed on "table already exists". Each table and column is now created only if it's missing, and rolling back drops only what exists.

---
## Upgrading
If a v0.7.0 upgrade stopped in this migration, run `php artisan migrate` again after upgrading. It picks up where it stopped. If it stopped on `order_configs` with `Cannot drop index '<unknown key name>': needed in a foreign key constraint`, that error comes from MySQL's own table metadata, not from the migration, and the table needs repairing before the migration can finish.

---
## Need help?
- [GitHub Discussions](https://github.com/fleetbase/fleetbase/discussions)
- [Discord](https://discord.gg/HnTqQ6zAVn)
---
