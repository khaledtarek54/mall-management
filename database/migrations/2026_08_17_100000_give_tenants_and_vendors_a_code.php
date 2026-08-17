<?php

use App\Models\Tenant;
use App\Models\Vendor;
use App\Support\DocumentNumbering;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Give the two counterparties a number of their own.
 *
 * Every other party and document in this system already has one — a unit has `code`, an employee
 * has a staff number, a lease has a reference, an invoice has a number — and the two that people
 * talk about most did not. So "which Zara?" was a question that could only be answered with a
 * sentence, on the phone, in an email, and in the one place it hurts most: the search box, where
 * the operator had nothing short and unambiguous to type.
 *
 * ## The three states this has to survive
 *
 * 1. **Existing rows.** Backfilled here, ordered by `id` so the sequence matches the order the
 *    records were actually created — an operator scanning the list sees the oldest tenant as
 *    TN-0000001, which is the reading they expect.
 * 2. **Rows created after this.** Allocated by the model under the document-number lock
 *    (`Tenant::booted()`), same as every other number in the system.
 * 3. **Imported rows that already have a code.** Kept. An operator migrating off Yardi arrives
 *    with tenant codes their accountant already uses, and renumbering them would break every
 *    reconciliation they have. The model only allocates when the column is blank.
 *
 * ## Nullable, with a unique index
 *
 * Not NOT NULL: `code` is allocated in `creating`, so a `NOT NULL` column would refuse the insert
 * of any row created by a path that has not been through the model — and the seeders, the factories
 * and `DB::table()->insert()` in older migrations are all such paths. The UNIQUE index is what
 * actually protects the invariant, exactly as it does for `invoices.number`.
 *
 * The `search_text` blob is rebuilt at the end, because the fold is a stored snapshot: adding the
 * code to `searchTextSources()` changes nothing for a row already in the table until something
 * re-saves it. Skipping this is how the codes would have been findable on new tenants only —
 * which is worse than not shipping them, because it looks like it works.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['tenants' => 'tenant', 'vendors' => 'vendor'] as $table => $type) {
            if (Schema::hasColumn($table, 'code')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->string('code', 40)->nullable()->after('id');
                $blueprint->unique('code', "{$table}_code_unique");
            });

            $this->backfill($table, DocumentNumbering::prefixFor($type));
        }

        $this->rebuildSearchText();
    }

    /**
     * Number the rows that already exist, oldest first.
     *
     * Chunked and written one row at a time rather than as a single CASE expression: the tables are
     * small (a mall runs tens of tenants, not millions) and a per-row UPDATE is the version that
     * behaves identically on MySQL and on the sqlite `:memory:` database the test suite migrates
     * into. A migration that only works on one driver fails at the least convenient moment.
     */
    protected function backfill(string $table, string $prefix): void
    {
        $sequence = 0;

        DB::table($table)->orderBy('id')->select('id')->chunk(200, function ($rows) use ($table, $prefix, &$sequence): void {
            foreach ($rows as $row) {
                DB::table($table)
                    ->where('id', $row->id)
                    ->update(['code' => sprintf('%s-%07d', $prefix, ++$sequence)]);
            }
        });
    }

    /**
     * Re-fold both models' search blobs so the new codes are actually searchable.
     *
     * Guarded on the class existing and the trait being wired, so a fresh install that runs the
     * whole migration stack before the app is bootable does not die here.
     */
    protected function rebuildSearchText(): void
    {
        foreach ([Tenant::class, Vendor::class] as $model) {
            if (! class_exists($model) || ! Schema::hasColumn((new $model)->getTable(), 'search_text')) {
                continue;
            }

            $model::query()->chunkById(200, function ($records): void {
                foreach ($records as $record) {
                    $record->refreshSearchText();
                    $record->saveQuietly();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (['tenants', 'vendors'] as $table) {
            if (! Schema::hasColumn($table, 'code')) {
                continue;
            }

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropUnique("{$table}_code_unique");
                $blueprint->dropColumn('code');
            });
        }
    }
};
