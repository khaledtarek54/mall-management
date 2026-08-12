<?php

use App\Support\ValueSets;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * **The last 62 DB-level enum columns become strings.** No enum column remains, on either driver.
 *
 * CLAUDE.md has said `string` + Laravel validation, never `$table->enum(...)`, for as long as the
 * rule has existed; 62 columns said otherwise. The set they enforced moves to
 * {@see ValueSets}, checked on `saving` for every model, so the constraint is not lost
 * — it moves to the layer that sees services, jobs, importers and console commands, and not just the
 * form. `NoDatabaseEnumsConformanceTest` now fails on any enum column at all.
 *
 * **Why this was worth a migration of its own.** Adding one value meant `ALTER TABLE … MODIFY` — on
 * `payments.method`, the hottest table in the system — so an operator could not add a payment rail
 * without a deploy, in a market where the rails keep moving (Fawry, Meeza, Aman, Vodafone Cash). It
 * had already cost two migrations in three days, and `free_charges_type_from_its_db_enum` records an
 * enum silently breaking the charge-code catalogue's recurring-billing promise, which was most of the
 * money.
 *
 * **And it closes a false green that had been recorded backwards.** The old registry stated the suite
 * enforced the identical sets, since Laravel renders `enum()` on SQLite as `varchar check (…)`. It
 * does — until anything calls `->change()` on the table. SQLite has no `ALTER COLUMN`, so Laravel
 * rebuilds the table from the introspected schema, which knows the column is a `varchar` and nothing
 * about the check; every check constraint on that table is dropped, silently. 24 of the 62 had been
 * freed that way on SQLite while remaining enums on MySQL, so a gate reading the live schema counted
 * 38 in tests and passed while production carried 62 — and a value the model allowed but MySQL
 * refused would have been green here and fatal on the first real save. That is the `escalation_type`
 * bug this project already paid for once.
 *
 * **`varchar(32)`** — the convention already used by the three columns freed before this one, and the
 * longest value across all 62 sets is 18 characters (`natural_breakpoint`), so nothing is truncated
 * and there is room for the values an operator will add.
 *
 * The spec below is generated from `information_schema` on a freshly migrated MySQL database, so the
 * nullability and default of every column are carried across exactly as they were.
 */
return new class extends Migration
{
    /**
     * `[table, column, nullable, default, values]` — the pre-conversion state of all 62 columns.
     *
     * The value lists are duplicated from `ValueSets::SETS` on purpose, which is the one place this
     * project's "never re-list a registry" rule does not apply: a migration is a historical artifact,
     * and `down()` must restore the enum that existed on 2026-08-12, not whatever the registry has
     * grown to by the time someone rolls back.
     *
     * @var list<array{0: string, 1: string, 2: bool, 3: string|null, 4: list<string>}>
     */
    private const COLUMNS = [
        ['accounting_periods', 'status', false, 'open', ['open', 'closed']],
        ['assets', 'type', false, 'mall', ['mall', 'retail_walk', 'mixed_use', 'office', 'residential']],
        ['cam_allocations', 'status', false, 'pending', ['pending', 'billed', 'disputed', 'closed']],
        ['cam_expense_pools', 'status', false, 'draft', ['draft', 'reconciling', 'reconciled', 'closed']],
        ['charges', 'frequency', false, 'monthly', ['monthly', 'quarterly', 'annually', 'one_time']],
        ['credit_notes', 'status', false, 'draft', ['draft', 'issued', 'applied', 'void']],
        ['deposit_transactions', 'method', false, 'bank', ['cash', 'bank']],
        ['deposit_transactions', 'status', false, 'recorded', ['recorded', 'cancelled']],
        ['deposit_transactions', 'type', false, null, ['receipt', 'refund', 'forfeit']],
        ['device_tokens', 'platform', false, null, ['ios', 'android']],
        ['employees', 'status', false, 'active', ['active', 'terminated']],
        ['expenses', 'paid_from', false, 'cash', ['cash', 'bank']],
        ['expenses', 'status', false, 'recorded', ['recorded', 'cancelled']],
        ['fiscal_years', 'status', false, 'open', ['open', 'closed']],
        ['fixed_assets', 'status', false, 'active', ['active', 'disposed']],
        ['invoices', 'eta_status', true, null, ['pending', 'submitted', 'valid', 'invalid', 'rejected', 'cancelled']],
        ['invoices', 'status', false, 'draft', [
            'draft', 'issued', 'partially_paid', 'paid', 'overdue', 'disputed', 'cancelled', 'credited',
            'written_off',
        ]],
        ['journal_entries', 'status', false, 'draft', ['draft', 'posted', 'void']],
        ['leases', 'billing_frequency', false, 'monthly', ['monthly', 'quarterly', 'semiannual', 'annual']],
        ['leases', 'percentage_rent_calculation_type', true, null, ['natural_breakpoint', 'artificial', 'tiered']],
        ['leases', 'status', false, 'draft', [
            'draft', 'pending_approval', 'active', 'expired', 'renewed', 'terminated', 'cancelled',
        ]],
        ['ledger_accounts', 'normal_balance', false, null, ['debit', 'credit']],
        ['ledger_accounts', 'type', false, null, ['asset', 'liability', 'equity', 'revenue', 'expense']],
        ['maintenance_penalties', 'basis', false, null, ['flat', 'per_day', 'percent_of_value']],
        ['maintenance_penalties', 'status', false, 'pending', ['pending', 'final', 'applied', 'waived']],
        ['maintenance_plans', 'maintenance_type', false, 'routine', ['routine', 'fixed']],
        ['maintenance_work_order_items', 'result', false, 'pending', ['pending', 'pass', 'fail']],
        ['maintenance_work_order_parts', 'source', false, null, ['internal', 'external']],
        ['maintenance_work_order_parts', 'status', false, 'pending', ['pending', 'approved', 'rejected', 'recorded']],
        ['maintenance_work_orders', 'execution_type', true, null, ['internal', 'external']],
        ['maintenance_work_orders', 'priority', false, 'medium', ['low', 'medium', 'high', 'urgent']],
        ['maintenance_work_orders', 'work_order_type', false, 'ppm', ['ppm', 'cm']],
        ['marketing_budgets', 'status', false, 'open', ['open', 'closed']],
        ['marketing_spends', 'category', false, 'other', ['offer', 'promotion', 'event', 'printed_work', 'other']],
        ['marketing_spends', 'paid_from', false, 'cash', ['cash', 'bank']],
        ['notes', 'channel', false, 'other', ['call', 'whatsapp', 'email', 'meeting', 'site_visit', 'other']],
        ['owner_requests', 'priority', false, 'medium', ['low', 'medium', 'high']],
        ['owner_requests', 'recipient', false, 'operator', ['operator', 'owner']],
        ['owner_requests', 'status', false, 'open', ['open', 'in_progress', 'resolved', 'closed', 'cancelled']],
        ['payments', 'method', false, null, [
            'card', 'bank_transfer', 'instapay', 'wallet', 'cash', 'cheque', 'other',
        ]],
        ['payments', 'status', false, 'captured', [
            'initiated', 'authorized', 'captured', 'reconciled', 'settled', 'failed', 'refunded', 'bounced',
        ]],
        ['payrolls', 'paid_from', false, 'bank', ['cash', 'bank']],
        ['payrolls', 'status', false, 'draft', ['draft', 'approved', 'cancelled']],
        ['purchase_requests', 'status', false, 'requested', [
            'requested', 'approved', 'rejected', 'ordered', 'received', 'cancelled',
        ]],
        ['sla_policies', 'priority', false, null, ['low', 'medium', 'high', 'urgent']],
        ['stock_movements', 'type', false, null, [
            'receipt', 'consumption', 'adjustment', 'transfer_in', 'transfer_out',
        ]],
        ['tenant_requests', 'channel', false, 'portal', ['portal', 'whatsapp', 'phone', 'email', 'walk_in', 'admin']],
        ['tenant_requests', 'priority', false, 'medium', ['low', 'medium', 'high', 'urgent']],
        ['tenant_requests', 'status', false, 'submitted', [
            'submitted', 'acknowledged', 'in_progress', 'awaiting_tenant', 'resolved', 'closed', 'cancelled',
        ]],
        ['tenant_sales_declarations', 'status', false, 'submitted', ['submitted', 'locked', 'disputed']],
        ['tenants', 'status', false, 'active', ['active', 'inactive', 'blacklisted']],
        ['tenants', 'type', false, 'company', ['individual', 'company']],
        ['units', 'category', false, 'retail', [
            'retail', 'food_beverage', 'wellness', 'service', 'kiosk', 'office', 'storage',
        ]],
        ['units', 'status', false, 'vacant', ['vacant', 'reserved', 'occupied', 'maintenance']],
        ['utility_meters', 'status', false, 'active', ['active', 'inactive', 'faulty']],
        ['utility_meters', 'type', false, null, ['electric', 'water', 'gas']],
        ['vendor_bill_payments', 'method', false, 'bank_transfer', [
            'cash', 'bank_transfer', 'cheque', 'card', 'other',
        ]],
        ['vendor_bills', 'status', false, 'draft', ['draft', 'approved', 'partially_paid', 'paid', 'cancelled']],
        ['vendor_contracts', 'sla_penalty_basis', false, 'none', ['none', 'flat', 'per_day', 'percent_of_value']],
        ['vendor_contracts', 'status', false, 'draft', ['draft', 'active', 'expired', 'terminated']],
        ['vendors', 'status', false, 'active', ['active', 'inactive', 'blacklisted']],
        ['vendors', 'type', false, 'service_provider', [
            'contractor', 'supplier', 'service_provider', 'consultant', 'other',
        ]],
    ];

    public function up(): void
    {
        $this->rewrite(function (Blueprint $blueprint, string $column, bool $nullable, ?string $default): void {
            $definition = $blueprint->string($column, 32);

            if ($nullable) {
                $definition->nullable();
            }

            if ($default !== null) {
                $definition->default($default);
            }

            $definition->change();
        });
    }

    /**
     * Restores the enums exactly as they were.
     *
     * A value added to a set after this migration ran is, by design, not in the list below — freeing
     * the column is what allows it. So a rollback on a database that used the freedom is a data
     * question before it is a schema one: MySQL in strict mode refuses the `ALTER`, which is the
     * honest failure rather than silently emptying those rows.
     */
    public function down(): void
    {
        $this->rewrite(function (Blueprint $blueprint, string $column, bool $nullable, ?string $default, array $values): void {
            $definition = $blueprint->enum($column, $values);

            if ($nullable) {
                $definition->nullable();
            }

            if ($default !== null) {
                $definition->default($default);
            }

            $definition->change();
        });
    }

    /**
     * Applies one column rewrite to every registered column, grouped so each table is altered once.
     *
     * Runs unconditionally rather than only on columns that are still enums, so any database
     * converges on the same shape whatever state it is in — a developer's incrementally-migrated
     * MySQL, a fresh deploy, or the SQLite test schema where 24 of these were already plain
     * `varchar`. Re-stating a column as what it already is costs one no-op `ALTER` on MySQL.
     */
    private function rewrite(callable $rewriteColumn): void
    {
        $byTable = [];

        foreach (self::COLUMNS as [$table, $column, $nullable, $default, $values]) {
            $byTable[$table][] = [$column, $nullable, $default, $values];
        }

        foreach ($byTable as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $columns = array_values(array_filter(
                $columns,
                fn (array $c): bool => Schema::hasColumn($table, $c[0]),
            ));

            if ($columns === []) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($columns, $rewriteColumn): void {
                foreach ($columns as [$column, $nullable, $default, $values]) {
                    $rewriteColumn($blueprint, $column, $nullable, $default, $values);
                }
            });
        }
    }
};
