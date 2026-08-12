<?php

use App\Filament\Imports\LeaseImporter;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Validation\ValidationException;

/**
 * The lease import must actually import a lease.
 *
 * **No test in this repository has ever executed an importer.** The only importer test inspects
 * validation *rules* on `TenantImporter` — it never runs a row. That is why four separate faults
 * could sit on the cut-over path, the one path a real go-live cannot avoid, with a green suite:
 *
 *  1. `$this` inside a closure built in `static getColumns()` → `unit_id` never set, and
 *     `leases.unit_id` is NOT NULL. PHPStan reported it twice; both entries were baselined.
 *  2. An `asset_code` column with no fill → Filament wrote `$record->asset_code`, a column that
 *     does not exist → `SQLSTATE[42S22]` on every row.
 *  3. No charge schedule → the lease imports and then bills **nothing**, because
 *     `MonthlyBillingService` reads the schedule and not the lease's own columns.
 *  4. Not idempotent and not property-clamped.
 *
 * These tests drive `Importer::__invoke()` directly — the same entry point `ImportCsv` uses per
 * row — so they exercise resolveRecord → validate → fill → save → afterCreate exactly as a real
 * upload does.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'MALL']);
    $this->unit = makeUnit($this->asset, ['code' => 'A-101', 'status' => 'vacant']);
    $this->tenant = makeTenant(['email' => 'shop@brand.test']);

    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'leases.csv',
        'file_path' => 'leases.csv',
        'importer' => LeaseImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => User::factory()->create()->id,
    ]);
});

function importLeaseRow(array $row): void
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();

    // The same call ImportCsv makes for each CSV line.
    (new LeaseImporter(test()->import, $columnMap, []))($row);
}

function leaseRow(array $overrides = []): array
{
    return array_merge([
        'asset_code' => 'MALL',
        'unit_code' => 'A-101',
        'tenant_email' => 'shop@brand.test',
        'reference' => 'CONTRACT-2019-0042',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'term_months' => '12',
        'base_rent_monthly' => '10000',
        'service_charge_monthly' => '1500',
        'security_deposit' => '30000',
        'status' => 'active',
    ], $overrides);
}

it('imports a lease and attaches it to the right unit and tenant', function () {
    importLeaseRow(leaseRow());

    $lease = Lease::where('reference', 'CONTRACT-2019-0042')->first();

    // Fault 1 + 2: before the fix this row died on `Unknown column 'asset_code'`, and had it got
    // past that, unit_id would have been null against a NOT NULL column.
    expect($lease)->not->toBeNull()
        ->and($lease->unit_id)->toBe($this->unit->id)
        ->and($lease->tenant_id)->toBe($this->tenant->id)
        ->and((float) $lease->base_rent_monthly)->toBe(10000.0);
});

it('gives the imported lease a charge schedule, so it actually bills', function () {
    importLeaseRow(leaseRow());

    $lease = Lease::where('reference', 'CONTRACT-2019-0042')->first();
    $charges = Charge::where('lease_id', $lease->id)->get();

    // Fault 3. Billing reads the schedule, never the lease's own columns — a lease with no charge
    // rows is skipped as `no_applicable_charges` and the operator's first month bills nothing.
    expect($charges)->not->toBeEmpty()
        ->and($charges->pluck('type'))->toContain('base_rent')
        ->and((float) $charges->firstWhere('type', 'base_rent')->amount)->toBe(10000.0);
});

it('preserves the operator existing contract reference', function () {
    importLeaseRow(leaseRow(['reference' => 'JAWAD/2018/SHOP-7']));

    // Importing existing leases means importing the references the operator already uses; a
    // renamed lease cannot be reconciled against their own paperwork.
    expect(Lease::where('reference', 'JAWAD/2018/SHOP-7')->exists())->toBeTrue();
});

it('is idempotent — re-running the same file updates rather than duplicates', function () {
    importLeaseRow(leaseRow());
    importLeaseRow(leaseRow(['base_rent_monthly' => '11000']));

    // Fault 4. Re-running a partial import is the normal response to a partial import; the old
    // code minted a fresh reference each run and double-booked the unit.
    expect(Lease::where('reference', 'CONTRACT-2019-0042')->count())->toBe(1)
        ->and((float) Lease::where('reference', 'CONTRACT-2019-0042')->value('base_rent_monthly'))->toBe(11000.0);
});

it('does not stack a second charge schedule on re-import', function () {
    importLeaseRow(leaseRow());
    importLeaseRow(leaseRow());

    $lease = Lease::where('reference', 'CONTRACT-2019-0042')->first();

    // Overlapping charge rows are their own documented failure mode: a lease with two open-ended
    // base_rent rows bills NOTHING, because the schedule is ambiguous.
    expect(Charge::where('lease_id', $lease->id)->where('type', 'base_rent')->count())->toBe(1);
});

it('treats one unit and commencement date as one tenancy when no reference is given', function () {
    importLeaseRow(leaseRow(['reference' => null]));
    importLeaseRow(leaseRow(['reference' => null]));

    // The same shop starting on the same day is the same lease, not a second one.
    expect(Lease::where('unit_id', $this->unit->id)->count())->toBe(1);
});

it('allocates a reference when the file supplies none', function () {
    importLeaseRow(leaseRow(['reference' => null]));

    expect(Lease::where('unit_id', $this->unit->id)->value('reference'))
        ->toStartWith(Lease::referencePrefix('MALL'));
});

it('refuses a row whose unit belongs to a property the importer cannot see', function () {
    $other = makeAsset(['code' => 'POINT']);
    makeUnit($other, ['code' => 'B-202', 'status' => 'vacant']);

    // A real operator restricted to MALL — not a mock. `visibleAssetIds()` reads the signed-in
    // user's assigned set, so faking it would prove nothing about the clamp.
    auth()->login(makeUser('manager', [$this->asset->id]));

    importLeaseRow(leaseRow(['asset_code' => 'POINT', 'unit_code' => 'B-202']));

    // An import bypasses the Create/Edit pages where assertAssetInScope() runs, so the clamp has
    // to live in the importer. LeaseImporter called withoutGlobalScopes() with no check at all —
    // it copied UnitImporter's lookup and dropped the half that makes it safe.
    expect(Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $other->id))->count())->toBe(0);
});

it('still imports the property that operator CAN see — the paired control', function () {
    // Without this, the refusal above would pass just as happily if the importer were broken
    // outright, which is precisely the state it was in.
    auth()->login(makeUser('manager', [$this->asset->id]));

    importLeaseRow(leaseRow());

    expect(Lease::where('reference', 'CONTRACT-2019-0042')->exists())->toBeTrue();
});

it('does not leave a tenant orphaned when the email is unknown', function () {
    importLeaseRow(leaseRow(['tenant_email' => 'nobody@nowhere.test']));

    // tenant_id is NOT NULL, so an unmatched email must fail the row rather than write a half
    // lease. Nothing should have been created.
    expect(Lease::where('reference', 'CONTRACT-2019-0042')->exists())->toBeFalse()
        ->and(Tenant::where('email', 'nobody@nowhere.test')->exists())->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| Term ⇄ expiry, on the bulk path too
|--------------------------------------------------------------------------
| The lease FORM derives these both ways since 2026-08-12, so an operator cannot type "36 months"
| spanning twelve. The importer could still create one — at a hundred rows a time — because it took
| a commencement, an optional expiry and an optional term with no relationship between them. A
| migrated lease whose term contradicts its expiry carries that contradiction into renewal and
| option exercise, both of which read the term.
*/

it('fills in an expiry the file does not carry', function () {
    importLeaseRow(leaseRow(['reference' => 'CT-DERIVE-1', 'expiry_date' => '', 'term_months' => '24']));

    $lease = Lease::where('reference', 'CT-DERIVE-1')->sole();

    expect($lease->expiry_date->toDateString())->toBe('2027-12-31')
        ->and($lease->term_months)->toBe(24);
});

it('fills in a term the file does not carry', function () {
    importLeaseRow(leaseRow(['reference' => 'CT-DERIVE-2', 'expiry_date' => '2027-06-30', 'term_months' => '']));

    $lease = Lease::where('reference', 'CT-DERIVE-2')->sole();

    expect($lease->term_months)->toBe(18)
        ->and($lease->expiry_date->toDateString())->toBe('2027-06-30');
});

it('refuses a row whose term and expiry contradict each other', function () {
    // Neither is preferred, because nothing here can know which is wrong: the expiry is a contract
    // date and the term is a description of it. A failed row names the problem while the CSV is
    // still open — the same call `atriom:audit-charge-schedules` makes about bad legacy data.
    expect(fn () => importLeaseRow(leaseRow([
        'reference' => 'CT-CLASH',
        'commencement_date' => '2026-01-01',
        'term_months' => '36',
        'expiry_date' => '2026-12-31',
    ])))->toThrow(ValidationException::class);

    expect(Lease::where('reference', 'CT-CLASH')->exists())->toBeFalse();
});

it('accepts a row where they agree — the paired control', function () {
    // Without this, the refusal above would pass just as happily if every row were rejected.
    importLeaseRow(leaseRow(['reference' => 'CT-AGREE', 'commencement_date' => '2026-01-01', 'term_months' => '12', 'expiry_date' => '2026-12-31']));

    expect(Lease::where('reference', 'CT-AGREE')->exists())->toBeTrue();
});

it('leaves a bespoke end date alone rather than rounding it into a term', function () {
    // A lease aligned to a financial year is not a whole number of months. The column keeps its
    // default instead of being given a rounded lie, and the negotiated date stands.
    importLeaseRow(leaseRow(['reference' => 'CT-BESPOKE', 'commencement_date' => '2026-01-01', 'expiry_date' => '2027-03-15', 'term_months' => '']));

    $lease = Lease::where('reference', 'CT-BESPOKE')->sole();

    expect($lease->expiry_date->toDateString())->toBe('2027-03-15')
        // The column is NOT NULL, so it takes the whole months the range COVERS — 14 months and a
        // fortnight is recorded as 14, with the exact end date beside it.
        ->and($lease->term_months)->toBe(14);
});
