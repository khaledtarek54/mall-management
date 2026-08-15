<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * **The facility module stops calling itself "maintenance" too.**
 *
 * Phase A freed the tenant-request module (2026_08_15_140000). This is the other half: the module
 * that genuinely *does* maintenance also stops using the word as an identifier, because "the
 * maintenance module" was never distinguishable from "the maintenance requests" by name alone —
 * and the two are different modules, with different RBAC, different screens and different tables.
 *
 * What it is instead: **Facility** — the nav group these screens have always lived under. Plans
 * become `service_plans` (they schedule any facility service, not only maintenance — the
 * generalisation landed 2026_07_22), and penalties become `sla_penalties`, matching the two
 * services that already assess and apply them (`AssessSlaPenaltyService`, `ApplySlaPenaltyService`).
 *
 * ⚠️ **The risky half is the polymorphic backfill, not the rename.** No morph map is registered
 * (`Relation::enforceMorphMap()` is called nowhere), so every polymorphic column stores a
 * fully-qualified class name. `journal_entries.source_type` is the one that matters: `LedgerPoster`
 * re-reads a posted entry's source to decide whether to void and re-post it, so a row left holding
 * a class that no longer exists does not error — it silently re-journals. `assertNoStaleMorphs()`
 * below fails the migration rather than leaving that to be discovered later.
 *
 * Index names are deliberately NOT renamed. They are cosmetic, and on SQLite a rename forces a
 * table rebuild that drops the CHECK constraints standing in for the enums we removed
 * (`NoDatabaseEnumsConformanceTest` guards those). Column and table renames are native ALTERs on
 * both drivers and carry no such cost.
 */
return new class extends Migration
{
    /** old table => new table */
    private const TABLES = [
        'maintenance_plans' => 'service_plans',
        'maintenance_work_orders' => 'facility_work_orders',
        'maintenance_work_order_items' => 'facility_work_order_items',
        'maintenance_work_order_parts' => 'facility_work_order_parts',
        'maintenance_penalties' => 'sla_penalties',
    ];

    /** new table name => [old column => new column] */
    private const COLUMNS = [
        'service_plans' => ['maintenance_type' => 'plan_type'],
        'facility_work_orders' => ['maintenance_plan_id' => 'service_plan_id'],
        'facility_work_order_items' => ['maintenance_work_order_id' => 'facility_work_order_id'],
        'facility_work_order_parts' => ['maintenance_work_order_id' => 'facility_work_order_id'],
        'sla_penalties' => ['maintenance_work_order_id' => 'facility_work_order_id'],
    ];

    /** old FQCN => new FQCN */
    private const CLASSES = [
        'App\\Models\\MaintenancePlan' => 'App\\Models\\ServicePlan',
        'App\\Models\\MaintenanceWorkOrder' => 'App\\Models\\FacilityWorkOrder',
        'App\\Models\\MaintenanceWorkOrderItem' => 'App\\Models\\FacilityWorkOrderItem',
        'App\\Models\\MaintenanceWorkOrderPart' => 'App\\Models\\FacilityWorkOrderPart',
        'App\\Models\\MaintenancePenalty' => 'App\\Models\\SlaPenalty',
    ];

    /**
     * Every polymorphic TYPE column in the schema. Listed exhaustively rather than only the ones
     * believed to hold these classes: a column missed here fails silently, and the cost of
     * updating one that holds nothing matching is a no-op UPDATE.
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private const MORPH_COLUMNS = [
        ['journal_entries', 'source_type'],
        ['stock_movements', 'source_type'],
        ['posting_month_overrides', 'source_type'],
        ['activity_log', 'subject_type'],
        ['activity_log', 'causer_type'],
        ['media', 'model_type'],
        ['notes', 'noteable_type'],
    ];

    /** old permission => new. The facility module: plans + work orders + equipment. */
    private const PERMISSIONS = [
        'preventive_maintenance.view' => 'facility.view',
        'preventive_maintenance.create' => 'facility.create',
        'preventive_maintenance.edit' => 'facility.edit',
        'preventive_maintenance.delete' => 'facility.delete',
        'preventive_maintenance.complete' => 'facility.complete',
        'preventive_maintenance.view_all' => 'facility.view_all',
        'preventive_maintenance.attribute_fault' => 'facility.attribute_fault',
    ];

    public function up(): void
    {
        $this->renameTables(self::TABLES);
        $this->renameColumns(self::COLUMNS);
        $this->remapMorphs(self::CLASSES);
        $this->renamePermissions(self::PERMISSIONS);
        $this->assertNoStaleMorphs(array_keys(self::CLASSES));
    }

    public function down(): void
    {
        $this->renameColumns($this->flipColumns(self::COLUMNS));
        $this->renameTables(array_flip(self::TABLES));
        $this->remapMorphs(array_flip(self::CLASSES));
        $this->renamePermissions(array_flip(self::PERMISSIONS));
    }

    /** @param array<string, string> $map */
    private function renameTables(array $map): void
    {
        foreach ($map as $from => $to) {
            if (Schema::hasTable($from) && ! Schema::hasTable($to)) {
                Schema::rename($from, $to);
            }
        }
    }

    /** @param array<string, array<string, string>> $map */
    private function renameColumns(array $map): void
    {
        foreach ($map as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            foreach ($columns as $from => $to) {
                if (Schema::hasColumn($table, $from) && ! Schema::hasColumn($table, $to)) {
                    Schema::table($table, fn (Blueprint $t) => $t->renameColumn($from, $to));
                }
            }
        }
    }

    /**
     * `renameColumns()` takes new-table => [old => new]; reversing needs the SAME table keys (the
     * tables are renamed back afterwards, so they still carry their new names at this point) with
     * the column pairs flipped.
     *
     * @param  array<string, array<string, string>>  $map
     * @return array<string, array<string, string>>
     */
    private function flipColumns(array $map): array
    {
        return array_map(fn (array $columns) => array_flip($columns), $map);
    }

    /** @param array<string, string> $map */
    private function remapMorphs(array $map): void
    {
        foreach (self::MORPH_COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            foreach ($map as $from => $to) {
                DB::table($table)->where($column, $from)->update([$column => $to]);
            }
        }
    }

    /**
     * A row still naming a class that no longer exists is the failure mode this whole method is
     * for — `LedgerPoster::sync()` reads `journal_entries.source_type` to find the document behind
     * a posted entry, and its answer to "no match" is to void and re-post. Fail the migration
     * instead, while the transaction can still be rolled back by hand.
     *
     * @param  array<int, string>  $goneClasses
     */
    private function assertNoStaleMorphs(array $goneClasses): void
    {
        $stale = [];

        foreach (self::MORPH_COLUMNS as [$table, $column]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }

            $found = DB::table($table)->whereIn($column, $goneClasses)->distinct()->pluck($column);

            foreach ($found as $class) {
                $stale[] = "{$table}.{$column} = {$class}";
            }
        }

        if ($stale !== []) {
            throw new RuntimeException(
                'Rename left polymorphic rows pointing at classes that no longer exist: '
                .implode(', ', $stale)
                .'. Add the missing column to MORPH_COLUMNS and re-run.'
            );
        }
    }

    /** @param array<string, string> $map */
    private function renamePermissions(array $map): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($map as $old => $new) {
            DB::table('permissions')->where('name', $old)->update(['name' => $new]);
        }

        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
