# The MySQL tier

Tests that **cannot** be expressed on SQLite, and are therefore invisible to `vendor/bin/pest`.

The suite runs on sqlite `:memory:`. That is the right default — it is roughly 9× faster and the
overwhelming majority of this system's behaviour is driver-agnostic. But three properties are not,
and each has already cost this project something:

| Property | What SQLite does | What it cost |
|---|---|---|
| `lockForUpdate()` | `SQLiteGrammar::compileLock()` returns `''` — every lock is inert | Two concurrency guards read from a stale snapshot and nothing turned red (pre-staging QA, F-09) |
| `->change()` on a table with a CHECK | Silently drops the constraint | 24 columns were enums in production and unconstrained in tests |
| `select tbl.*, x, *` | Accepted | MySQL calls it a syntax error; the fixed-asset list, the register CSV and **every global search** 500'd in production while 5,180 tests passed |
| An identifier over 64 characters | No limit | A migration whose auto-generated index name is too long passes the whole suite and fails on the first real deploy, part-applied — MySQL does not roll DDL back |

## The second kind of test here: the same sweep, on the other driver

`FilterSweepOnMysqlTest` is not a property sqlite cannot express — it is the ORDINARY filter sweep,
re-run against the real driver. It earns its place because the sqlite sweep's green is a statement
about sqlite, and a table's SQL is assembled from closures that can build a shape only one driver
rejects (`select tbl.*, x, *` above, an alias in a `having`, `ONLY_FULL_GROUP_BY`). Reported from
the panel on 2026-08-25 as a MySQL **1054** on eleven filters at once, which is what prompted it.

It is **read-only** and does not use `RefreshDatabase`: it reads the populated baseline rather than
migrating an empty database, because a filter that returns no rows exercises almost nothing — and
the coverage floors it asserts are what stop it reporting a clean run over a set it never touched.

Run it against a real database:

```bash
composer test:mysql          # needs mall_management_qa — build it with `composer qa:baseline`
```

It is a **separate** suite on purpose. It needs a live MySQL, so it cannot be part of the default
run without making the fast path depend on a service; and keeping it separate is what lets its
failures mean "this would break on the real database" rather than "your environment is off".
