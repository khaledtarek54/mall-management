<?php

use App\Models\Charge;
use App\Models\Invoice;
use App\Models\Lease;
use App\Services\MonthlyBillingService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The schedule rollout leaves every existing lease billing exactly what it billed (story LS-06).
 *
 * **The claim being tested.** Phase 1 turned a lease's rent from one mutable row into a date-ranged
 * schedule. Every lease signed before that still carries the old shape — an active charge with no
 * `start_date` and no `end_date` — and the whole of phase 1 rests on the assertion that such a row
 * bills identically under the new model. Nobody had ever run both and compared.
 *
 * **A null start has always meant "from the beginning"** (`chargeAppliesToPeriod()` skips the
 * comparison entirely when the column is null), so stamping the lease commencement onto it selects
 * exactly the same months. This file proves that rather than restating it: the same fixture bills
 * the same month before and after the migration, and the invoices are compared line by line.
 *
 * **The real deploy-night hazard is NOT the dates — it is the duplicates.** Under the old model two
 * active `base_rent` rows meant the run billed *both* and someone noticed the rent had doubled a
 * month later. Under the new one `assertScheduleUnambiguous()` refuses, which is the right call and
 * a far better failure — but it turns a quiet over-bill into a lease that bills **nothing at all**.
 * `atriom:audit-charge-schedules` is what finds those before the run does, and the tests at the
 * bottom of this file are the reason to trust it.
 *
 * **Those fixtures insert raw rows on purpose.** `Charge` gained a model-level overlap guard in
 * phase 1 and now refuses to *create* the shape at all — which is why the audit is not redundant
 * with it. The guard protects everything written from here on; the audit is for the rows that were
 * already in the database when it shipped, and raw insert is the only honest way to reproduce them.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

/** A lease shaped the way every pre-phase-1 lease is: one active row per type, no dates at all. */
function legacyShapedLease(string $unitCode): Lease
{
    $lease = makeLease(makeUnit(makeAsset(), ['code' => $unitCode, 'area_sqm' => 100]), null, [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
        'base_rent_monthly' => 30000,
        'service_charge_monthly' => 5000,
    ])->fresh();

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 30000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => null, 'end_date' => null, 'is_active' => true,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'amount' => 5000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => true, 'vat_rate' => Vat::standardRate(),
        'start_date' => null, 'end_date' => null, 'is_active' => true,
    ]);

    return $lease->fresh();
}

/**
 * A second open-ended row of the same type, inserted the way legacy data exists.
 *
 * Deliberately NOT through `Charge::create()` — the model's overlap guard refuses this shape now.
 * The rows this simulates were written before that guard existed.
 */
function rawDuplicateRentRow(Lease $lease, float $amount): void
{
    DB::table('charges')->insert([
        'lease_id' => $lease->id, 'name' => 'Base Rent (duplicate)', 'type' => 'base_rent',
        'amount' => $amount, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => null, 'end_date' => null, 'is_active' => true,
        'created_at' => now(), 'updated_at' => now(),
    ]);
}

/** Run the LS-06 migration exactly as `artisan migrate` would. */
function stampLegacyStartDates(): object
{
    return require database_path('migrations/2026_08_09_233000_stamp_start_dates_on_legacy_charge_rows.php');
}

/** Everything about an invoice that a tenant or an accountant would notice. */
function invoiceFingerprint(Invoice $invoice): array
{
    return [
        'period' => [$invoice->period_start?->toDateString(), $invoice->period_end?->toDateString()],
        'issue_date' => $invoice->issue_date?->toDateString(),
        'due_date' => $invoice->due_date?->toDateString(),
        'subtotal' => (float) $invoice->subtotal,
        'vat_amount' => (float) $invoice->vat_amount,
        'total' => (float) $invoice->total,
        'items' => $invoice->items()->orderBy('id')->get()
            ->map(fn ($i) => [
                'type' => $i->type,
                'description' => $i->description,
                'quantity' => (float) $i->quantity,
                'unit_price' => (float) $i->unit_price,
                'vat_rate' => (float) $i->vat_rate,
                'vat_amount' => (float) $i->vat_amount,
                'total' => (float) $i->total,
            ])->all(),
    ];
}

it('bills a legacy lease identically before and after the migration', function () {
    // THE story's acceptance. Same fixture, same month, both sides of the migration.
    CarbonImmutable::setTestNow('2026-03-05');
    $billing = app(MonthlyBillingService::class);

    $before = legacyShapedLease('L-A');
    $billing->generateForLease($before, CarbonImmutable::parse('2026-03-01'));
    $beforeFingerprint = invoiceFingerprint($before->invoices()->sole());

    $after = legacyShapedLease('L-B');
    stampLegacyStartDates()->up();
    $billing->generateForLease($after->fresh(), CarbonImmutable::parse('2026-03-01'));

    // The description carries the unit code, which differs by construction — compare everything else.
    $afterFingerprint = invoiceFingerprint($after->invoices()->sole());
    $strip = fn (array $f) => [...$f, 'items' => array_map(
        fn (array $i) => [...$i, 'description' => preg_replace('/L-[AB]/', 'UNIT', (string) $i['description'])],
        $f['items'],
    )];

    expect($strip($afterFingerprint))->toBe($strip($beforeFingerprint));
});

it('stamps the lease commencement onto an undated row', function () {
    $lease = legacyShapedLease('L-C');

    expect($lease->charges()->whereNull('start_date')->count())->toBe(2);

    stampLegacyStartDates()->up();

    expect($lease->charges()->whereNull('start_date')->count())->toBe(0)
        ->and($lease->charges()->pluck('start_date')->map->toDateString()->unique()->all())
        ->toBe(['2026-01-01']);
});

it('leaves the end date open, so a holdover lease keeps billing', function () {
    // A deliberate deviation from the story's wording ("`to` = expiry"). Atriom bills holdover from
    // the same charge rows, so stamping the expiry would stop the rent on the day the term ended —
    // which is a behaviour change, and the story's whole point is that there must not be one.
    $lease = legacyShapedLease('L-D');

    stampLegacyStartDates()->up();

    expect($lease->charges()->whereNotNull('end_date')->count())->toBe(0);
});

it('is reversible', function () {
    $lease = legacyShapedLease('L-F');
    $migration = stampLegacyStartDates();

    $migration->up();
    expect($lease->charges()->whereNull('start_date')->count())->toBe(0);

    $migration->down();
    expect($lease->charges()->whereNull('start_date')->count())->toBe(2);
});

it('finds a lease whose duplicate rows would now refuse to bill', function () {
    // The hazard the audit exists for. Under the OLD model this lease billed 30,000 + 12,000 and
    // someone noticed a month later; under the new one it bills nothing at all.
    $lease = legacyShapedLease('L-G');
    rawDuplicateRentRow($lease, 12000);

    $this->artisan('atriom:audit-charge-schedules')
        ->expectsOutputToContain('OVERLAP')
        ->assertFailed();
});

it('proves the overlap it reports is a real refusal, not a theory', function () {
    // A finding nobody can reproduce is a finding nobody fixes. This is the same lease, billed.
    CarbonImmutable::setTestNow('2026-03-05');
    $lease = legacyShapedLease('L-H');
    rawDuplicateRentRow($lease, 12000);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-03-01'));

    // The refusal is caught and reported rather than thrown — so on deploy night this lease does
    // not crash the run, it simply produces no invoice, which is the quieter and worse outcome and
    // exactly why the audit has to run BEFORE the billing does.
    expect($result['status'])->toBe('failed')
        ->and($result['invoice'])->toBeNull()
        ->and($lease->invoices()->count())->toBe(0);
});

it('passes a lease whose schedule is a proper ladder', function () {
    // The control. A refusal test passes just as happily when everything is refused.
    $lease = legacyShapedLease('L-I');
    $lease->charges()->where('type', 'base_rent')->update([
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
    $lease->charges()->where('type', 'service_charge')->update(['start_date' => '2026-01-01']);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 32100, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2027-01-01', 'end_date' => null, 'is_active' => true,
    ]);

    $this->artisan('atriom:audit-charge-schedules')->assertSuccessful();
});

it('reports a month the lease bills no rent for', function () {
    // Quieter than an overlap and worse to find later: the invoice is produced, it is just missing
    // a line, and the first person to notice is the tenant who was not charged.
    $lease = legacyShapedLease('L-J');
    $lease->charges()->where('type', 'base_rent')->update([
        'start_date' => '2026-01-01', 'end_date' => '2026-06-30',
    ]);
    $lease->charges()->where('type', 'service_charge')->update(['start_date' => '2026-01-01']);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 32100, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-09-01', 'end_date' => null, 'is_active' => true,
    ]);

    $this->artisan('atriom:audit-charge-schedules')
        ->expectsOutputToContain('gap')
        ->assertFailed();
});
