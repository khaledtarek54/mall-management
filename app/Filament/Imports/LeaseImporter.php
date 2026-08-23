<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesVisibleAssetByCode;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Services\LeaseCreationService;
use App\Support\Filament\CustomFieldsTable;
use App\Support\LeaseTerm;
use Carbon\CarbonImmutable;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

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

            // Either of these may be blank — {@see afterValidate()} derives the missing one from
            // the other, and refuses a row where both are present and disagree.
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
                // Deliberately NARROWER than the set `leases.status` accepts (App\Support\ValueSets),
                // so this is not read from the registry: 'pending_approval' and 'cancelled' are
                // reached through the approval and cancellation workflows, and importing a lease
                // straight into either would skip the steps that put it there.
                ->rules(['nullable', 'in:draft,active,expired,renewed,terminated']),

            // The operator's own fields (D-7), LAST so an existing mapping template's column
            // order is untouched. Optional: a sheet that names none imports as it always did.
            ...CustomFieldsTable::importColumns('lease'),
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
    /**
     * Make the imported term and expiry agree — or refuse the row.
     *
     * The lease FORM derives these both ways (`App\Support\LeaseTerm`), so an operator cannot type
     * "36 months" spanning twelve. **The importer could still create one**, and at a hundred rows a
     * time: it took a commencement, an optional expiry and an optional term with no relationship
     * between them. A migrated lease whose `term_months` contradicts its `expiry_date` carries that
     * contradiction into renewal and option exercise, which both read the term.
     *
     * Three cases, and the third is the one worth arguing about:
     *
     *   - **Expiry missing** → derive it from the term. The commencement is required, so this
     *     always resolves, and it is what the creation service does.
     *   - **Term missing** → derive it from the expiry. Null when the range is not a whole number
     *     of months, which a bespoke end date legitimately is not — the column keeps its default
     *     rather than being given a rounded lie.
     *   - **Both present and disagreeing** → **refuse the row.** One of the two is wrong and
     *     nothing here can know which: the expiry is a contract date and the term is a description
     *     of it, and silently preferring either would rewrite what the operator's spreadsheet says.
     *     A failed row names the problem while the CSV is still open, which is the whole point of
     *     catching it at import rather than in year three. Same call as
     *     `atriom:audit-charge-schedules`, which surfaces bad legacy data instead of normalising it.
     */
    protected function afterValidate(): void
    {
        $commencement = $this->data['commencement_date'] ?? null;
        $expiry = $this->data['expiry_date'] ?? null;
        $term = $this->data['term_months'] ?? null;

        if (blank($commencement)) {
            return;
        }

        if (blank($expiry) && filled($term)) {
            $this->data['expiry_date'] = LeaseTerm::expiryFrom($commencement, $term);

            return;
        }

        if (blank($term) && filled($expiry)) {
            // `monthsSpanning()`, not `monthsBetween()`: `leases.term_months` is NOT NULL, and a
            // bespoke end date is not a whole number of months — so the column takes how many whole
            // months the range covers, while the EXPIRY, which is the contract date, is stored
            // exactly. Writing null here is the failure mode this codebase names by name.
            $this->data['term_months'] = LeaseTerm::monthsSpanning($commencement, $expiry);

            return;
        }

        if (blank($expiry) || blank($term)) {
            return;
        }

        $derived = LeaseTerm::expiryFrom($commencement, $term);

        if ($derived !== null && $derived !== CarbonImmutable::parse($expiry)->toDateString()) {
            throw ValidationException::withMessages([
                'expiry_date' => __('admin.validation.import_lease_term_disagrees', [
                    'term' => (int) $term,
                    'derived' => $derived,
                    'expiry' => CarbonImmutable::parse($expiry)->toDateString(),
                ]),
            ]);
        }
    }

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
