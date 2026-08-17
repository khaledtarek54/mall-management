<?php

use App\Filament\Imports\ChargeImporter;
use App\Filament\Imports\EmployeeImporter;
use App\Filament\Imports\MeterReadingImporter;
use App\Models\Charge;
use App\Models\Employee;
use App\Models\MeterReading;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Support\Vat;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\TaxCodeSeeder;
use Filament\Actions\Imports\Models\Import;

/**
 * The last three cut-over importers: payroll, charge schedules and meter readings.
 *
 * Each is the file an operator otherwise re-keys by hand on go-live morning, and each has one way of
 * going quietly wrong that these pin:
 *
 *  - **Employees** — identity is the national id, never the name. Two staff share a name eventually,
 *    and a re-import that merged them leaves one person unpaid.
 *  - **Charges** — written through `ChargeScheduleService`, because a lease's charges are a dated
 *    SCHEDULE. Two rows overlapping a month make the billing run refuse, and it bills **nothing** for
 *    that lease. Inserting rows straight into the table is the fastest way to do that a hundred times.
 *  - **Meter readings** — cost derives from the meter's tariff, and a reading that has already raised
 *    a recharge invoice is evidence for it and must not be overwritten by a re-upload.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(TaxCodeSeeder::class);
    $this->seed(ChargeCodeSeeder::class);

    $this->asset = makeAsset(['code' => 'MALL']);

    $this->import = Import::create([
        'completed_at' => null,
        'file_name' => 'cutover.csv',
        'file_path' => 'cutover.csv',
        'importer' => EmployeeImporter::class,
        'processed_rows' => 0,
        'total_rows' => 1,
        'successful_rows' => 0,
        'user_id' => User::factory()->create()->id,
    ]);
});

/** Run one CSV row through an importer exactly as Filament does — the class is invokable. */
function importRow(string $importer, array $row): void
{
    $columnMap = collect(array_keys($row))->mapWithKeys(fn ($k) => [$k => $k])->all();

    (new $importer(test()->import, $columnMap, []))($row);
}

it('identifies an employee by national id, not by name', function () {
    // Two people, same name. Merging them means one of them is not on the payroll.
    importRow(EmployeeImporter::class, [
        'asset_code' => 'MALL', 'code' => 'E-001', 'name' => 'Ahmed Hassan',
        'national_id' => '29001011234567', 'base_salary' => 6000, 'hire_date' => '2024-03-01',
    ]);
    importRow(EmployeeImporter::class, [
        'asset_code' => 'MALL', 'code' => 'E-002', 'name' => 'Ahmed Hassan',
        'national_id' => '29505054321098', 'base_salary' => 7500, 'hire_date' => '2024-05-01',
    ]);

    expect(Employee::count())->toBe(2);
});

it('updates rather than duplicates when the same employee is re-imported', function () {
    importRow(EmployeeImporter::class, [
        'asset_code' => 'MALL', 'code' => 'E-001', 'name' => 'Ahmed Hassan',
        'national_id' => '29001011234567', 'base_salary' => 6000, 'hire_date' => '2024-03-01',
    ]);
    importRow(EmployeeImporter::class, [
        'asset_code' => 'MALL', 'code' => 'E-001', 'name' => 'Ahmed Hassan',
        'national_id' => '29001011234567', 'base_salary' => 6500, 'hire_date' => '2024-03-01',
    ]);

    expect(Employee::count())->toBe(1)
        ->and((float) Employee::sole()->base_salary)->toBe(6500.0);
});

it('refuses an employee row naming a property code that does not exist', function () {
    expect(fn () => importRow(EmployeeImporter::class, [
        'asset_code' => 'NOT-A-MALL', 'code' => 'E-009', 'name' => 'Somebody',
        'national_id' => '30000000000000', 'base_salary' => 5000, 'hire_date' => '2024-03-01',
    ]))->toThrow(RuntimeException::class);
});

it('refuses an employee row for a REAL property the importer may not write to', function () {
    // The clamp, tested properly. My first attempt used a code that did not exist, so it passed
    // whether or not the scope check was there — the mutation proved it. This uses a real second
    // mall and a user assigned only to the first: an import bypasses the Create page where
    // `assertAssetInScope()` runs, so without the clamp a restricted user staffs another mall's
    // payroll from a CSV.
    $other = makeAsset(['code' => 'OTHER']);
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    // Matched on the MESSAGE, not just the class: without the clamp the row still throws — on the
    // NOT NULL constraint for `asset_id` — and a QueryException IS a RuntimeException, so asserting
    // the class alone passed with the guard deleted. The mutation caught that.
    expect(fn () => importRow(EmployeeImporter::class, [
        'asset_code' => 'OTHER', 'code' => 'E-010', 'name' => 'Somebody Else',
        'national_id' => '30000000000001', 'base_salary' => 5000, 'hire_date' => '2024-03-01',
    ]))->toThrow(RuntimeException::class, 'out-of-scope');

    expect(Employee::where('asset_id', $other->id)->count())->toBe(0);
});

it('builds a charge SCHEDULE, closing the outgoing rung instead of overlapping it', function () {
    // The headline. Two rates for one charge must become two abutting rungs; overlapping rows make
    // the billing run refuse and the lease bills nothing at all.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'reference' => 'L-2026-001', 'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31',
    ]);

    importRow(ChargeImporter::class, [
        'lease_reference' => 'L-2026-001', 'type' => 'service_charge',
        'amount' => 5000, 'effective_from' => '2026-01-01',
    ]);
    importRow(ChargeImporter::class, [
        'lease_reference' => 'L-2026-001', 'type' => 'service_charge',
        'amount' => 6000, 'effective_from' => '2026-07-01',
    ]);

    $rows = Charge::where('lease_id', $lease->id)->where('type', 'service_charge')
        ->orderBy('start_date')->get();

    expect($rows)->toHaveCount(2)
        // The first rung is CLOSED the day before the second opens — that is what stops the overlap.
        ->and($rows->first()->end_date?->toDateString())->toBe('2026-06-30')
        ->and($rows->last()->start_date->toDateString())->toBe('2026-07-01')
        ->and($rows->last()->end_date)->toBeNull();
});

it('leaves an imported charge on the tax catalogue rather than freezing today\'s rate', function () {
    // A blank VAT column must stay NULL. Writing the catalogue's current answer onto the row is
    // exactly what stopped a future rate change ever reaching recurring rent.
    makeLease(makeUnit($this->asset), makeTenant(), [
        'reference' => 'L-2026-002', 'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31',
    ]);

    importRow(ChargeImporter::class, [
        'lease_reference' => 'L-2026-002', 'type' => 'service_charge',
        'amount' => 5000, 'effective_from' => '2026-01-01', 'vat_rate' => '',
    ]);

    $row = Charge::where('type', 'service_charge')->latest('id')->first();

    expect($row->vat_rate)->toBeNull()
        ->and($row->resolvedVatRate())->toBe(Vat::rateForType('service_charge'));
});

it('keeps a VAT rate the file deliberately states', function () {
    makeLease(makeUnit($this->asset), makeTenant(), [
        'reference' => 'L-2026-003', 'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31',
    ]);

    importRow(ChargeImporter::class, [
        'lease_reference' => 'L-2026-003', 'type' => 'service_charge',
        'amount' => 5000, 'effective_from' => '2026-01-01', 'vat_rate' => '5',
    ]);

    expect((float) Charge::where('type', 'service_charge')->latest('id')->first()->vat_rate)->toBe(5.0);
});

it('refuses a charge row for a lease the importer cannot see', function () {
    expect(fn () => importRow(ChargeImporter::class, [
        'lease_reference' => 'L-NOT-A-LEASE', 'type' => 'service_charge',
        'amount' => 5000, 'effective_from' => '2026-01-01',
    ]))->toThrow(RuntimeException::class);
});

it('derives a reading\'s cost from the meter tariff when the file leaves it blank', function () {
    $meter = UtilityMeter::create([
        'asset_id' => $this->asset->id, 'meter_number' => 'EL-001',
        'type' => 'electric', 'rate_per_unit' => 2.5, 'status' => 'active',
    ]);

    importRow(MeterReadingImporter::class, [
        'meter_number' => 'EL-001', 'reading_date' => '2026-06-30',
        'reading_value' => 12000, 'consumption' => 400, 'cost' => '',
    ]);

    expect((float) MeterReading::where('utility_meter_id', $meter->id)->sole()->cost)->toBe(1000.0);
});

it('treats a stated cost as an override of the tariff', function () {
    UtilityMeter::create([
        'asset_id' => $this->asset->id, 'meter_number' => 'EL-002',
        'type' => 'electric', 'rate_per_unit' => 2.5, 'status' => 'active',
    ]);

    importRow(MeterReadingImporter::class, [
        'meter_number' => 'EL-002', 'reading_date' => '2026-06-30',
        'reading_value' => 12000, 'consumption' => 400, 'cost' => 950,
    ]);

    expect((float) MeterReading::sole()->cost)->toBe(950.0);
});

it('updates a reading re-uploaded for the same meter and date', function () {
    UtilityMeter::create([
        'asset_id' => $this->asset->id, 'meter_number' => 'EL-003',
        'type' => 'electric', 'rate_per_unit' => 2, 'status' => 'active',
    ]);

    $row = ['meter_number' => 'EL-003', 'reading_date' => '2026-06-30', 'reading_value' => 500, 'consumption' => 100, 'cost' => ''];
    importRow(MeterReadingImporter::class, $row);
    importRow(MeterReadingImporter::class, [...$row, 'consumption' => 120]);

    expect(MeterReading::count())->toBe(1)
        ->and((float) MeterReading::sole()->consumption)->toBe(120.0);
});

it('refuses to overwrite a reading that has already been billed', function () {
    // The reading is the evidence for a recharge invoice already sent to the tenant. Changing it
    // underneath would leave the document and its basis disagreeing.
    $meter = UtilityMeter::create([
        'asset_id' => $this->asset->id, 'meter_number' => 'EL-004',
        'type' => 'electric', 'rate_per_unit' => 2, 'status' => 'active',
    ]);
    $lease = makeLease(makeUnit($this->asset), makeTenant());
    $invoice = makeInvoice($lease, ['status' => 'issued', 'subtotal' => 200, 'vat_amount' => 0, 'total' => 200, 'paid_amount' => 0, 'balance' => 200]);

    MeterReading::create([
        'utility_meter_id' => $meter->id, 'reading_date' => '2026-06-30',
        'reading_value' => 500, 'consumption' => 100, 'cost' => 200,
        'billed_invoice_id' => $invoice->id, 'billed_at' => now(),
    ]);

    expect(fn () => importRow(MeterReadingImporter::class, [
        'meter_number' => 'EL-004', 'reading_date' => '2026-06-30',
        'reading_value' => 500, 'consumption' => 999, 'cost' => '',
    ]))->toThrow(RuntimeException::class);

    expect((float) MeterReading::sole()->consumption)->toBe(100.0);
});

it('refuses a reading against a meter number that does not exist', function () {
    expect(fn () => importRow(MeterReadingImporter::class, [
        'meter_number' => 'NOT-A-METER', 'reading_date' => '2026-06-30',
        'reading_value' => 1, 'consumption' => 1, 'cost' => '',
    ]))->toThrow(RuntimeException::class);
});

it('refuses a reading against a REAL meter on another mall', function () {
    // Same correction as the employee case: a non-existent meter number proves nothing about the
    // scope clamp. A reading uploaded against another mall's meter recharges that mall's tenant.
    $other = makeAsset(['code' => 'OTHER']);
    UtilityMeter::create([
        'asset_id' => $other->id, 'meter_number' => 'EL-OTHER',
        'type' => 'electric', 'rate_per_unit' => 2, 'status' => 'active',
    ]);
    $this->actingAs(makeUser('manager', [$this->asset->id]));

    expect(fn () => importRow(MeterReadingImporter::class, [
        'meter_number' => 'EL-OTHER', 'reading_date' => '2026-06-30',
        'reading_value' => 1, 'consumption' => 1, 'cost' => '',
    ]))->toThrow(RuntimeException::class, 'out-of-scope');

    expect(MeterReading::count())->toBe(0);
});
