<?php

namespace App\Support;

/**
 * The DB-level enum columns that still exist — a burn-down list, and a line under it.
 *
 * **The convention is `string` + Laravel/Filament validation, never `$table->enum(...)`.** It is
 * recorded in CLAUDE.md and it is the one long-standing convention with no gate — which is exactly
 * why 38 of them survive.
 *
 * **The count is 38, not the 62 the sweep recorded, and the difference is instructive.** That
 * figure was read off a developer's local MySQL, which had been migrated incrementally for months
 * and still carried columns that later migrations had already freed. The test database is rebuilt
 * from every migration on each run, so it is the only honest answer — 24 of the 62 were ghosts.
 * CLAUDE.md's own rule applies to sweeps as much as to docs: never hand-count, ask the system.
 *
 * **What this is NOT about.** The initial framing — "a value the model allows but the column
 * refuses" — was checked and disproved: Laravel renders `enum()` on SQLite as `varchar check (…)`,
 * so the test suite enforces the identical set, and a diff of every model's `STATUS_*` / `TYPE_*` /
 * `METHOD_*` constant against the DB sets found **zero mismatches**. There is no false-green hole
 * here and this registry does not pretend otherwise.
 *
 * **What it IS about: deploy cost and operator autonomy.** Adding one value means an
 * `ALTER TABLE … MODIFY` — on `payments.status`, the hottest table in the system. It has already
 * cost two migrations in three days, and `free_charges_type_from_its_db_enum`'s own docblock records
 * that the enum had *silently broken the charge-code catalogue's recurring-billing promise*. The
 * operator cannot add a payment rail without a deploy, and Egypt's rails keep moving — Fawry, Meeza,
 * Aman, Vodafone Cash.
 *
 * So: the 38 below are GRANDFATHERED, {@see FREE_THESE} names the ten worth freeing first, and
 * `NoDatabaseEnumsConformanceTest` fails on a 39th. It reads the **live schema** rather than the
 * migrations, because the schema is the truth regardless of how a column got there — including
 * when a column was freed by raw SQL, or its table renamed.
 *
 * **Removing an entry is how this list shrinks — the gate fails on a stale one.** That is the
 * lesson from the PHPStan baseline, which quietly rotted until a fifth of it described errors that
 * no longer existed: a list nobody can tell is out of date reports coverage it does not have.
 */
class DatabaseEnums
{
    /**
     * Enum columns that already exist. Do not add to this list — free the column instead.
     *
     * @var array<int, string>
     */
    public const GRANDFATHERED = [
        'accounting_periods.status',
        'credit_notes.status',
        'deposit_transactions.method',
        'deposit_transactions.status',
        'deposit_transactions.type',
        'device_tokens.platform',
        'employees.status',
        'expenses.paid_from',
        'expenses.status',
        'fiscal_years.status',
        'fixed_assets.status',
        'journal_entries.status',
        'ledger_accounts.normal_balance',
        'ledger_accounts.type',
        'maintenance_penalties.status',
        'maintenance_work_order_items.result',
        'maintenance_work_order_parts.source',
        'maintenance_work_order_parts.status',
        'marketing_budgets.status',
        'notes.channel',
        'owner_requests.priority',
        'owner_requests.recipient',
        'owner_requests.status',
        'payments.method',
        'payments.status',
        'payrolls.paid_from',
        'payrolls.status',
        'purchase_requests.status',
        'sla_policies.priority',
        'stock_movements.type',
        'tenants.status',
        'tenants.type',
        'utility_meters.status',
        'utility_meters.type',
        'vendor_contracts.sla_penalty_basis',
        'vendor_contracts.status',
        'vendors.status',
        'vendors.type',
    ];

    /**
     * The subset genuinely extensible by an operator or accountant — free these first.
     *
     * The other 28 are engineering state machines (`journal_entries.status`, `credit_notes.status`)
     * whose values the code branches on; widening those is a code change anyway, so the enum costs
     * nothing and documents the contract. These ten are different: the person who needs a new value
     * cannot write PHP.
     *
     * @var array<string, string>
     */
    public const FREE_THESE = [
        'payments.method' => 'Egypt\'s payment rails keep moving — Fawry, Meeza, Aman, Vodafone Cash — and each new one is a blocking ALTER on the hottest table in the system.',
        'deposit_transactions.method' => 'The deposit-side mirror of the same problem.',
        'utility_meters.type' => 'No district cooling — ordinary in a Cairo mall.',
        'tenants.type' => 'The retail mix is the operator\'s vocabulary, not ours.',
        'vendors.type' => 'A supplier taxonomy the procurement lead maintains.',
        'expenses.paid_from' => 'One of three `cash|bank` pairs that already LOSE to the `bank_accounts` table: with more than one bank, `paid_from = \'bank\'` cannot say which.',
        'payrolls.paid_from' => 'Same pair.',
        'notes.channel' => 'How the operator reached someone — WhatsApp today, whatever is next.',
        'sla_policies.priority' => 'A priority ladder the operations lead should be able to extend.',
        'owner_requests.priority' => 'Same ladder, owner side.',
    ];
}
