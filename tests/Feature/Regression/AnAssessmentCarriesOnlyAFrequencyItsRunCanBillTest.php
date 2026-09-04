<?php

declare(strict_types=1);

use App\Enums\PartyType;
use App\Filament\Imports\ChargeImporter;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\UnitOwnership;
use App\Services\BillUnitOwnershipsService;
use App\Services\ChargeScheduleService;
use App\Support\ValueSets;
use Carbon\CarbonImmutable;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Importer;

/**
 * AN ASSESSMENT SCHEDULE CARRIES ONLY A FREQUENCY THE RUN THAT BILLS IT CAN ANSWER. — SW-045
 *
 * `BillUnitOwnershipsService::appliesToPeriod()` bills a `monthly` row every month and a
 * `one_time` row once, in the month its start date falls in. It answers **false** for `quarterly`
 * and `annually`, and it always has. The unit-ownership charges form knew that — its picker offers
 * exactly two options, under a comment saying a quarterly row *"would be silently ignored, which is
 * worse than not offering it"*.
 *
 * `ChargeImporter` did not. Its `frequency` column validates against
 * `ValueSets::allowed('charges', 'frequency')` — all four values the column may hold — so a
 * migrating operator's quarterly صيانة assessment imported cleanly, rendered on the schedule as a
 * live charge, and was counted by every monthly run in the ordinary `skipped` counter, beside the
 * tenures that genuinely owed nothing that month. The owner was never billed, for the life of the
 * ownership, and nothing anywhere said so. That is the pattern this module was built out of (F-01,
 * the assessment run with no caller) arriving through the import door instead.
 *
 * The rule now lives ONCE — `BillableAgreement::billableChargeFrequencies()` — and
 * `Charge::assertFrequencyIsBillable()` asks it at the model, so the importer, the ownership form's
 * direct `Charge::create()` and the next door onto the table are all covered.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function assessmentOwnership(): UnitOwnership
{
    $asset = makeAsset();

    return UnitOwnership::create([
        'asset_id' => $asset->id,
        'unit_id' => makeUnit($asset)->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'tenure_type' => 'freehold',
        'status' => 'handed_over',
        'assessment_basis' => 'area',
        'ownership_share_pct' => 100,
        'started_at' => '2026-01-01',
        'handover_date' => '2026-01-01',
        'payment_terms_days' => 15,
        'currency' => 'EGP',
    ]);
}

/** @return array<string, mixed> */
function assessmentChargeAttributes(UnitOwnership $ownership, string $frequency): array
{
    return [
        'unit_ownership_id' => $ownership->id,
        'name' => 'Service charge',
        'type' => 'service_charge',
        'amount' => 2500,
        'currency' => 'EGP',
        'frequency' => $frequency,
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-03-01',
        'is_active' => true,
    ];
}

it('refuses an assessment frequency the run cannot bill, and names the ones it can', function (string $frequency) {
    $ownership = assessmentOwnership();

    $message = null;

    try {
        Charge::create(assessmentChargeAttributes($ownership, $frequency));
    } catch (DomainException $e) {
        $message = $e->getMessage();
    }

    // A refusal has to name the ESCAPE, or the operator is only told that they are wrong.
    expect($message)->not->toBeNull()
        ->and($message)->toContain(__('admin.charge_schedule.frequencies.monthly'))
        ->and($message)->toContain(__('admin.charge_schedule.frequencies.one_time'))
        ->and(Charge::where('unit_ownership_id', $ownership->id)->count())->toBe(0);
})->with(['quarterly', 'annually']);

it('bills every frequency the ownership says its own run can handle', function (string $frequency) {
    // The list and the run cannot drift: whatever `BILLABLE_CHARGE_FREQUENCIES` offers must
    // actually produce a document, so widening it without teaching `appliesToPeriod()` fails here.
    $ownership = assessmentOwnership();
    Charge::create(assessmentChargeAttributes($ownership, $frequency));

    $invoice = app(BillUnitOwnershipsService::class)->billOne(
        $ownership->fresh(),
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    );

    expect($invoice)->not->toBeNull();
})->with(UnitOwnership::BILLABLE_CHARGE_FREQUENCIES);

it('leaves a lease free to use every frequency its own run answers', function (string $frequency) {
    // The guard is agreement-specific, not a blanket ban: `MonthlyBillingService` has a match arm
    // for all four, so narrowing the lease side would break quarterly and annual lease charges.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
    ]);

    $charge = Charge::create([
        'lease_id' => $lease->id,
        'name' => 'Service charge',
        'type' => 'service_charge',
        'amount' => 1000,
        'currency' => 'EGP',
        'frequency' => $frequency,
        'vat_applicable' => false,
        'vat_rate' => 0,
        'start_date' => '2026-03-01',
        'is_active' => true,
    ]);

    expect($charge->exists)->toBeTrue()
        ->and($charge->fresh()->frequency)->toBe($frequency);
})->with(['monthly', 'quarterly', 'annually', 'one_time']);

it('asks the column what a lease may hold rather than keeping a second list', function () {
    expect((new Lease)->billableChargeFrequencies())
        ->toEqualCanonicalizing(ValueSets::allowed('charges', 'frequency'));
});

it('refuses through the schedule service the importer writes with', function () {
    $ownership = assessmentOwnership();
    $service = app(ChargeScheduleService::class);

    expect(fn () => $service->setAmount(
        $ownership,
        'service_charge',
        2500,
        CarbonImmutable::parse('2026-01-01'),
        ['name' => 'Service charge', 'frequency' => 'annually', 'first_row_from_effective' => true],
    ))->toThrow(DomainException::class);

    // …and the identical call with a frequency the run understands still writes the schedule, or
    // the guard would be indistinguishable from the importer simply being broken.
    $charge = $service->setAmount(
        $ownership,
        'service_charge',
        2500,
        CarbonImmutable::parse('2026-01-01'),
        ['name' => 'Service charge', 'frequency' => 'monthly', 'first_row_from_effective' => true],
    );

    expect($charge)->not->toBeNull()
        ->and($charge->frequency)->toBe('monthly');
});

it('hands the import failure CSV the sentence, not a blank row', function () {
    $ownership = assessmentOwnership();

    // Filament logs a bare `Throwable` as a failed row with NO message at all
    // (`ImportCsv::logFailedRow($row)`); only `RowImportFailedException` and `ValidationException`
    // carry one through to the operator's failure file.
    $importer = (new ReflectionClass(ChargeImporter::class))->newInstanceWithoutConstructor();
    $data = new ReflectionProperty(Importer::class, 'data');
    $data->setAccessible(true);
    $data->setValue($importer, [
        'ownership_reference' => $ownership->reference,
        'type' => 'service_charge',
        'amount' => '2500',
        'frequency' => 'quarterly',
        'effective_from' => '2026-01-01',
    ]);

    $thrown = null;

    try {
        $importer->resolveRecord();
    } catch (Throwable $e) {
        $thrown = $e;
    }

    expect($thrown)->toBeInstanceOf(RowImportFailedException::class)
        ->and($thrown->getMessage())->toContain(__('admin.charge_schedule.frequencies.monthly'));
});

it('leaves a row written before the guard billing nothing, rather than billing it monthly', function () {
    $ownership = assessmentOwnership();

    // `saveQuietly()` suppresses model events — which is how a row imported before this guard got
    // into the table, and the only way to make one now.
    (new Charge)->forceFill(assessmentChargeAttributes($ownership, 'quarterly'))->saveQuietly();

    expect(app(BillUnitOwnershipsService::class)->billOne(
        $ownership->fresh(),
        CarbonImmutable::parse('2026-03-01'),
        CarbonImmutable::parse('2026-03-31'),
    ))->toBeNull();

    // …and it stays an ordinary skip. The ownership HAS a schedule, so it is not `unconfigured`,
    // and a bug fix must not start invoicing a legacy row twelve times a year.
    $stats = app(BillUnitOwnershipsService::class)
        ->runForPeriod(CarbonImmutable::parse('2026-03-01'), $ownership->asset_id);

    expect($stats['created'])->toBe(0)
        ->and($stats['skipped'])->toBe(1)
        ->and($stats['unconfigured'])->toBe(0);
});
