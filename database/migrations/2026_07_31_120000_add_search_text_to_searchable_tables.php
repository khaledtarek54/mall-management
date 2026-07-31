<?php

use App\Services\Search\RebuildSearchIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds the denormalized `search_text` blob to every searchable table.
 *
 * The list below is a SNAPSHOT of `App\Support\SearchPolicy::INDEXED` as it stood
 * when this migration was written, deliberately hard-coded rather than derived
 * from the registry. A migration is a historical record: if it read the live
 * registry, its behaviour would change every time someone edited a constant, and
 * a database migrated last month would silently differ from one built from
 * scratch today.
 *
 * Adding a model to the registry therefore needs a NEW migration — and
 * `SearchPolicyConformanceTest` asserts that every registered model's table has
 * the column, so forgetting that fails the build with the table named, rather
 * than shipping a model whose search matches nothing.
 *
 * ## No index, on purpose
 *
 * Both Filament search paths build `LIKE '%term%'`. A leading wildcard cannot use
 * a B-tree index under any circumstance, so an index here would be write cost for
 * zero read benefit. MySQL would also refuse a plain index on a TEXT column
 * without a prefix length, and the prefix would have to be small enough to be
 * useless. Speed comes from the identifier fast-path (anchored `LIKE 'term%'`
 * against the real unique indexes on `number` / `reference` / `code`); this
 * column exists for CORRECTNESS — Arabic folding, accessor values, and
 * punctuation-insensitivity.
 */
return new class extends Migration
{
    /**
     * @var array<int, string>
     */
    private array $tables = [
        'tenants',
        'units',
        'leases',
        'assets',
        'areas',
        'invoices',
        'payments',
        'credit_notes',
        'deposit_transactions',
        'post_dated_cheques',
        'vendors',
        'vendor_bills',
        'expenses',
        'purchase_requests',
        'journal_entries',
        'ledger_accounts',
        'disbursements',
        'owner_statement_runs',
        'tenant_requests',
        'announcements',
        'violations',
        'owner_requests',
        'maintenance_work_orders',
        'maintenance_plans',
        'equipment',
        'utility_meters',
        'inventory_items',
        'warehouses',
        'stock_movements',
        'fixed_assets',
        'custodies',
        'employees',
        'payrolls',
        'users',
        'departments',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            if (Schema::hasColumn($table, 'search_text')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                // Nullable, with no default: a row written by something that
                // bypasses the model (a raw insert in an older migration, a
                // legacy import) reads as NULL — which the conformance gate can
                // detect as "never folded", where an empty-string default would
                // be indistinguishable from "folded to nothing".
                $blueprint->text('search_text')->nullable();
            });
        }

        // Populate what already exists. Without this, every record predating the
        // migration is unfindable — and unfindable-without-error is the exact
        // failure mode this whole change is here to remove.
        app(RebuildSearchIndex::class)();
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            if (! Schema::hasColumn($table, 'search_text')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropColumn('search_text');
            });
        }
    }
};
