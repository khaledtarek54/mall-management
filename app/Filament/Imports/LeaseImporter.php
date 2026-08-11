<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesVisibleAssetByCode;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use Carbon\CarbonImmutable;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * Bulk-load an operator's existing leases — the cut-over path.
 *
 * ## It did not work at all until 2026-08-11, in four stacked ways
 *
 * Each fault hid the next, which is why no single reviewer caught the set:
 *
 * 1. **`$this` inside a closure built in `static getColumns()`.** The `unit_code` column read
 *    `$this->data['asset_code']` to find the unit's property. No `$this` is bound in a closure
 *    defined in a static method, so `$assetCode` was never truthy, the branch setting
 *    `$record->unit_id` never ran, and `leases.unit_id` is NOT NULL. **PHPStan reported this
 *    twice** — `nullCoalesce.variable` and the consequent `if.alwaysFalse` — and both entries sat
 *    in `phpstan-baseline.neon`. Cross-field lookups now happen in `resolveRecord()`, which is an
 *    instance method where `$this->data` genuinely exists.
 *
 * 2. **A column that does not exist.** `asset_code` had no `fillRecordUsing()`, so Filament's
 *    default wrote `$record->asset_code` — and `leases` has no such column in any of the 195
 *    migrations. `asset_code`, `unit_code` and `tenant_email` are LOOKUP KEYS, not attributes;
 *    each now carries an explicit no-op fill saying so.
 *
 * 3. **No charge schedule.** `LeaseCreationService::seedStandardCharges()` has two callers and
 *    this was neither, so an imported lease billed **nothing**: `MonthlyBillingService` reads the
 *    schedule, not the columns, and returns `no_applicable_charges`. Seeded in `afterCreate()`
 *    now — only for genuinely new leases, so a re-import never doubles a schedule.
 *
 * 4. **Not idempotent, and not property-clamped.** Without a `reference` the old code minted a
 *    fresh one per run, so re-running a partial import duplicated every lease and double-booked
 *    its unit. And `resolveRecord()` called `withoutGlobalScopes()` with no visibility check —
 *    copying `UnitImporter`'s asset lookup while dropping the clamp that makes it safe.
 *
 * The safety net was blind too: `atriom:audit-charge-schedules` iterated a lease's charges, so a
 * lease with ZERO charges produced no findings and the command reported the import clean.
 *
 * **References are preserved, never regenerated.** An operator's existing contract references are
 * the point of importing; `Lease::creating` only allocates when the column is blank.
 */
class LeaseImporter extends Importer
{
    use ResolvesVisibleAssetByCode;

    protected static ?string $model = Lease::class;

    /**
     * The unit named by (asset_code, unit_code), or null.
     *
     * Named once and used for both the FK and the natural key. The original resolved the unit
     * inline in a static closure that could not see `asset_code` at all — which is the defect.
     *
     * @param  array<string, mixed>  $row
     */
    protected static function resolveUnit(array $row): ?Unit
    {
        $asset = static::resolveVisibleAsset(
            is_string($row['asset_code'] ?? null) ? $row['asset_code'] : null
        );

        if (! $asset || blank($row['unit_code'] ?? null)) {
            return null;
        }

        return Unit::withoutGlobalScopes()
            ->where('asset_id', $asset->id)
            ->where('code', $row['unit_code'])
            ->first();
    }

    public static function getColumns(): array
    {
        return [
            // ── Lookup keys ──────────────────────────────────────────────────────────────────
            // All three resolve in resolveRecord(); their fills are deliberate no-ops. Without a
            // fill, Filament's default `data_set($record, $name, $state)` writes an attribute —
            // which is exactly how `asset_code` came to be written to a table that has no such
            // column, failing every row with SQLSTATE[42S22].
            ImportColumn::make('asset_code')
                ->label(__('admin.tables.asset.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20', static::assetInScopeRule()])
                ->fillRecordUsing(function (Lease $record, string $state): void {
                    // No-op: a lease has no asset_id. Its property is reached through unit_id.
                }),

            ImportColumn::make('unit_code')
                ->label(__('admin.tables.unit.code'))
                ->requiredMapping()
                ->rules(['required', 'max:20'])
                ->fillRecordUsing(function (Lease $record, string $state): void {
                    // No-op: resolved against (asset_code, unit_code) in resolveRecord().
                }),

            ImportColumn::make('tenant_email')
                ->label(__('admin.tables.tenant.email'))
                ->requiredMapping()
                ->rules(['required', 'email', 'exists:tenants,email'])
                ->fillRecordUsing(function (Lease $record, string $state): void {
                    // No-op: resolved in resolveRecord().
                }),

            // ── Attributes ───────────────────────────────────────────────────────────────────
            ImportColumn::make('reference')
                ->label(__('admin.fields.reference'))
                ->rules(['nullable', 'max:50'])
                ->fillRecordUsing(function (Lease $record, ?string $state): void {
                    // A blank cell means "no reference in the file", never "clear the one it has".
                    // On a re-import of a reference-less file the default fill wrote null over the
                    // reference `Lease::creating` had allocated on the first pass — and
                    // `leases.reference` is NOT NULL, so the second run died on the UPDATE.
                    if (filled($state)) {
                        $record->reference = $state;
                    }
                }),

            ImportColumn::make('commencement_date')
                ->label(__('admin.fields.commencement_date'))
                ->requiredMapping()
                ->rules(['required', 'date']),

            ImportColumn::make('expiry_date')
                ->label(__('admin.fields.expiry_date'))
                ->rules(['nullable', 'date']),

            ImportColumn::make('term_months')
                ->label(__('admin.fields.term_months'))
                ->numeric()
                ->rules(['nullable', 'integer', 'min:1', 'max:120']),

            ImportColumn::make('base_rent_monthly')
                ->label(__('admin.fields.base_rent_monthly'))
                ->numeric()
                ->requiredMapping()
                ->rules(['required', 'numeric', 'min:0']),

            ImportColumn::make('service_charge_monthly')
                ->label(__('admin.fields.service_charge_monthly'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('security_deposit')
                ->label(__('admin.fields.security_deposit'))
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                ->rules(['nullable', 'in:draft,active,expired,renewed,terminated']),
        ];
    }

    /**
     * Find the lease this row refers to, or start a new one — with its unit and tenant resolved.
     *
     * An instance method, so `$this->data` is real here. That is the whole reason the cross-field
     * lookups moved out of `getColumns()`.
     */
    public function resolveRecord(): ?Lease
    {
        $unit = static::resolveUnit($this->data);
        $tenant = Tenant::where('email', $this->data['tenant_email'] ?? null)->first();

        // `unit_id` and `tenant_id` are both NOT NULL. Returning null SKIPS the row
        // (`Importer::__invoke` returns early on a null record) — which is the only safe answer
        // when a lookup finds nothing: the alternative is an insert that dies on an integrity
        // constraint naming a column the operator never had in their file.
        //
        // This also carries the property clamp. `resolveUnit()` goes through
        // `resolveVisibleAsset()`, so a row naming a mall the importer cannot see resolves to no
        // unit and is skipped. The old code called `withoutGlobalScopes()` with no check at all —
        // it copied UnitImporter's lookup and dropped the clamp that makes it safe.
        if (! $unit || ! $tenant) {
            return null;
        }

        $reference = $this->data['reference'] ?? null;

        // Idempotency, in the operator's terms first: a contract reference identifies a lease
        // across re-runs. Without one, (unit, commencement_date) is the natural key — the same
        // shop starting on the same day is the same tenancy, not a second one. The old code minted
        // a fresh reference per run instead, so re-running a partial import duplicated every lease
        // and double-booked its unit, with DeletionPolicy then refusing to clean up the copies.
        $lease = filled($reference)
            ? Lease::firstOrNew(['reference' => $reference])
            : Lease::firstOrNew([
                'unit_id' => $unit->id,
                // Normalised, because the CSV carries '2026-01-01' while the column stores a
                // datetime. Matching the raw string finds nothing on the second run, and the
                // "idempotent" natural key quietly becomes a duplicate factory.
                'commencement_date' => CarbonImmutable::parse($this->data['commencement_date'])->startOfDay(),
            ]);

        // Both are non-null by the guard above — set unconditionally.
        $lease->unit_id = $unit->id;
        $lease->tenant_id = $tenant->id;

        return $lease;
    }

    /**
     * Give the imported lease the charge schedule that makes it bill.
     *
     * `MonthlyBillingService` reads the date-ranged schedule, never the lease's own columns — so
     * without this an imported lease is skipped as `no_applicable_charges` and the operator's
     * first month bills nothing.
     *
     * `afterCreate` fires only when the record did not already exist (`Importer::__invoke`), so a
     * re-import cannot stack a second rent row on a lease that already has one — overlapping
     * charge rows are their own documented failure mode, and they bill NOTHING.
     */
    protected function afterCreate(): void
    {
        /** @var Lease $lease */
        $lease = $this->record;

        LeaseCreationService::seedStandardCharges(
            $lease,
            rent: (float) $lease->base_rent_monthly,
            service: (float) $lease->service_charge_monthly,
            commencement: $lease->commencement_date,
        );
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your lease import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }

    /**
     * Queue the import rather than running it inline.
     *
     * Was a hard-coded `'sync'`, which no configuration could override — so the cut-over, the
     * largest import this system will ever run, executed inside one HTTP request.
     */
    public function getJobConnection(): ?string
    {
        return config('imports.connection', 'sync');
    }

    /** A guard rail against a mis-mapped file, not a real limit — raise it deliberately. */
    public function getMaxRows(): ?int
    {
        return (int) config('imports.max_rows', 5000);
    }
}
