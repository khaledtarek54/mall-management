<?php

use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\Lease;
use App\Models\LedgerAccount;
use App\Services\CamReconciliationService;
use App\Services\CamStatementPdfService;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The reconciliation statement a tenant can audit (phase 6, story RC-06).
 *
 * Almost every commercial lease grants a service-charge audit right, and Atriom's answer to "show
 * me how you got this number" was an invoice line reading "CAM Recovery 2028". The tenant could see
 * the charge and nothing about its derivation.
 *
 * **The tests assert the FACTS, not the rendering.** A test that only checks the PDF is non-empty
 * passes just as happily when every number on it is wrong, which is the trap with document tests —
 * so `facts()` is a public seam and the arithmetic is pinned there. One rendering test proves the
 * bytes come out, in both languages, because an RTL font failure is real and silent.
 */
afterEach(fn () => CarbonImmutable::setTestNow());

function statementSetup(array $leaseAttrs = [], float $area = 100): array
{
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset, ['area_sqm' => $area]), null, array_merge([
        'status' => 'active',
        'commencement_date' => '2027-01-01',
        'expiry_date' => '2032-12-31',
        'base_rent_monthly' => 50000,
        'service_charge_monthly' => 5000,
        'has_marketing_levy' => false,
    ], $leaseAttrs));

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Service Charge', 'type' => 'service_charge',
        'origin' => Charge::ORIGIN_SEED, 'amount' => 5000, 'currency' => 'EGP',
        'frequency' => 'monthly', 'vat_applicable' => true, 'vat_rate' => Vat::standardRate(),
        'start_date' => '2027-01-01', 'is_active' => true,
    ]);

    return [$asset, $lease->fresh()];
}

it('states the pool, the denominator, the share and the settlement the tenant was actually billed', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = statementSetup();

    // A second lease so the denominator is genuinely bigger than this tenant's area.
    makeLease(makeUnit($asset, ['area_sqm' => 300]), null, [
        'status' => 'active', 'commencement_date' => '2027-01-01', 'expiry_date' => '2032-12-31',
    ]);

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 400000, 'total_estimated_collected' => 200000,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    $allocation = CamAllocation::where('cam_expense_pool_id', $pool->id)
        ->where('lease_id', $lease->id)->sole();

    $facts = app(CamStatementPdfService::class)->facts($allocation);

    expect($facts['year'])->toBe(2028)
        ->and($facts['pool_total'])->toBe(400000.0)
        ->and($facts['area_sqm'])->toBe(100.0)
        // 100 of 400 m² — the denominator RECOVERED from the stored share, so it is the one that
        // was actually used rather than the one that would be computed today.
        ->and($facts['share_pct'])->toBe(25.0)
        ->and($facts['denominator_sqm'])->toBe(400.0)
        ->and($facts['allocated'])->toBe(100000.0)          // 25% of 400,000
        ->and($facts['estimated_paid'])->toBe(50000.0)      // 25% of 200,000
        ->and($facts['true_up'])->toBe(50000.0)
        ->and($facts['true_up_is_credit'])->toBeFalse();
});

it('shows the cap and what the landlord absorbed, when a cap applied', function () {
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = statementSetup();

    $lease->camTerms()->create([
        'effective_year' => 2028,
        'cap_type' => 'absolute',
        'cap_absolute_amount' => 60000,
    ]);

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 400000, 'total_estimated_collected' => 200000,
    ]);

    app(CamReconciliationService::class)->generateAllocations($pool);

    $facts = app(CamStatementPdfService::class)->facts(
        CamAllocation::where('cam_expense_pool_id', $pool->id)->where('lease_id', $lease->id)->sole()
    );

    // The tenant's uncapped share is the whole pool (they are the only lease), capped at 60,000.
    expect($facts['cap_amount'])->toBe(60000.0)
        ->and($facts['capped_cost'])->toBe(60000.0)
        ->and($facts['cap_absorbed'])->toBe(340000.0)
        // …and the settlement is computed off the CAPPED cost, which is what the tenant bears.
        ->and($facts['true_up'])->toBe(-140000.0)
        ->and($facts['true_up_is_credit'])->toBeTrue();
});

it('reports no cap section for a lease that has none', function () {
    // The control: a "cap: none" row on every statement in the mall would train everyone to skip
    // the section that matters on the few where it bites.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = statementSetup();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 400000, 'total_estimated_collected' => 200000,
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $facts = app(CamStatementPdfService::class)->facts(
        CamAllocation::where('cam_expense_pool_id', $pool->id)->sole()
    );

    expect($facts['cap_amount'])->toBeNull()
        ->and($facts['capped_cost'])->toBe($facts['allocated']);
});

it('names the ledger accounts behind the pool when it was sourced from the GL', function () {
    // The audit answer to "where did 400,000 come from". A pool that was typed says so instead.
    CarbonImmutable::setTestNow('2029-01-15');
    test()->seed(ChartOfAccountsSeeder::class);
    [$asset, $lease] = statementSetup();

    $account = LedgerAccount::where('is_postable', true)->where('type', 'expense')->firstOrFail();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 400000, 'total_estimated_collected' => 200000,
        'expense_basis' => CamExpensePool::BASIS_LEDGER,
    ]);
    $pool->ledgerAccounts()->attach($account->id);

    app(CamReconciliationService::class)->generateAllocations($pool);

    $facts = app(CamStatementPdfService::class)->facts(
        CamAllocation::where('cam_expense_pool_id', $pool->id)->sole()
    );

    expect($facts['expense_basis'])->toBe(CamExpensePool::BASIS_LEDGER)
        ->and($facts['ledger_accounts'])->toHaveCount(1)
        ->and($facts['ledger_accounts'][0])->toContain($account->code);
});

it('carries next year’s proposed estimate onto the same document that explains the change', function () {
    // Telling the tenant the new monthly figure beside the reason for it is the difference between
    // a re-estimate and a surprise.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = statementSetup();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 240000, 'total_estimated_collected' => 60000,
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);

    $facts = app(CamStatementPdfService::class)->facts(
        CamAllocation::where('cam_expense_pool_id', $pool->id)->sole()
    );

    expect($facts['proposed_estimate'])->toBe(20000.0);
});

it('renders a real PDF in both languages', function () {
    // The rendering is worth exactly one test: an RTL font failure is real and it is silent.
    CarbonImmutable::setTestNow('2029-01-15');
    [$asset, $lease] = statementSetup();

    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2028, 'status' => 'draft',
        'total_actual_expense' => 400000, 'total_estimated_collected' => 200000,
    ]);
    app(CamReconciliationService::class)->generateAllocations($pool);
    $allocation = CamAllocation::where('cam_expense_pool_id', $pool->id)->sole();

    $svc = app(CamStatementPdfService::class);

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        $pdf = $svc->build($allocation);

        expect(substr($pdf, 0, 4))->toBe('%PDF')
            ->and(strlen($pdf))->toBeGreaterThan(2000);
    }

    app()->setLocale('en');

    expect($svc->filename($allocation))->toContain('2028')
        ->and($svc->filename($allocation))->toEndWith('.pdf');
});
