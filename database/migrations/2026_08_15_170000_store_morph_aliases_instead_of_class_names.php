<?php

use App\Support\MorphMap;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * **Every polymorphic column stops storing a class name and starts storing an alias.**
 *
 * Until now a fully-qualified class name WAS part of the schema: `activity_log.subject_type` held
 * `App\Models\Invoice`, `journal_entries.source_type` held `App\Models\MaintenancePenalty`, and so
 * on. That made renaming a model a data migration in seven places, and the failure was silent in
 * the worst one — `LedgerPoster::sync()` re-reads a posted entry's source to decide whether to void
 * and re-post it, and its answer to "that class does not exist" is to re-journal, not to error.
 * The 2026-08-15 facility rename had to hand-write exactly that backfill to survive moving five
 * models. This migration is what makes the next rename cost nothing.
 *
 * **Columns are DISCOVERED, not listed.** A hardcoded list is how the previous backfill nearly
 * missed `stock_movements.source_type`, so this walks every table and treats `X_type` as
 * polymorphic when a sibling `X_id` exists beside it — which is precisely Laravel's own convention
 * and therefore finds columns nobody remembered. It reports what it found, so the set is auditable
 * in the migration output rather than trusted.
 *
 * Note the discovery deliberately does NOT match `request_type`, `plan_type`, `work_order_type` or
 * the other value-carrying `*_type` columns: none of them has a sibling `*_id`, because they name a
 * category rather than point at a row.
 */
return new class extends Migration
{
    public function up(): void
    {
        // class name => alias
        $this->rewrite(array_flip(MorphMap::MAP));
    }

    public function down(): void
    {
        // alias => class name
        $this->rewrite(MorphMap::MAP);
    }

    /** @param  array<string, string>  $map  the value to find => the value to write */
    private function rewrite(array $map): void
    {
        foreach ($this->morphColumns() as [$table, $column]) {
            foreach ($map as $from => $to) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }
    }

    /**
     * Every `X_type` column that has a sibling `X_id` in the same table.
     *
     * Deduplicated because `getTableListing()` can return the same table once per schema visible to
     * the connection; `DB::table()` always resolves against the connection's own database, so a
     * repeat is wasted work rather than a cross-database write, but the report should still be the
     * honest set.
     *
     * Worth knowing what this finds beyond the obvious application columns: spatie's
     * `model_has_roles.model_type` and `model_has_permissions.model_type`, Sanctum's
     * `personal_access_tokens.tokenable_type`, and `notifications.notifiable_type`. All three are
     * load-bearing — miss the first and every user silently holds no roles, miss the second and API
     * tokens stop authenticating, miss the third and every bell inbox empties. None of them is
     * "ours", which is exactly why discovery beats a list somebody maintains by hand.
     *
     * @return array<int, array{0: string, 1: string}>
     */
    private function morphColumns(): array
    {
        $found = [];

        foreach (Schema::getTableListing() as $table) {
            // Some drivers qualify the listing with the schema name; normalise it.
            $table = str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;

            if (isset($found[$table]) || ! Schema::hasTable($table)) {
                continue;
            }

            $found[$table] = true;
            $columns = Schema::getColumnListing($table);

            foreach ($columns as $column) {
                if (! str_ends_with($column, '_type')) {
                    continue;
                }

                $sibling = substr($column, 0, -strlen('_type')).'_id';

                if (in_array($sibling, $columns, true)) {
                    $pairs[] = [$table, $column];
                }
            }
        }

        return $pairs ?? [];
    }
};
