<?php

require __DIR__.'/boot.php';
use App\Models\CamExpensePool;
use App\Services\Reconciliation\BooksReconciliationService;

$rec = app(BooksReconciliationService::class);
$camCheck = fn () => collect($rec->run(null, false)['checks'] ?? [])->firstWhere('key', 'cam_allocations');

qa_section('The CAM pool tie-out check, examined');
$c = $camCheck();
printf("  check: key=%s passed=%s discrepancies=%d\n", $c['key'], var_export($c['passed'], true), count($c['discrepancies']));
qa_ok('it passes on the seeded data', $c['passed'] === true);

qa_section('MUTATION — corrupt an allocation and see whether the check notices');
$pool = CamExpensePool::has('allocations')->first();
$alloc = $pool->allocations()->first();
printf("  pool #%d actual=%s Σ allocated=%s unrecovered=%s\n", $pool->id,
    number_format((float) $pool->total_actual_expense, 2),
    number_format((float) $pool->allocations()->sum('allocated_amount'), 2),
    number_format((float) $pool->landlord_unrecovered_amount, 2));

$orig = (float) $alloc->allocated_amount;
$alloc->forceFill(['allocated_amount' => $orig + 500000])->saveQuietly();
printf("  → inflated allocation #%d by 500,000 (now Σ allocated=%s, pool actual unchanged)\n",
    $alloc->id, number_format((float) $pool->allocations()->sum('allocated_amount'), 2));

$c2 = $camCheck();
printf("  check after corruption: passed=%s discrepancies=%d\n", var_export($c2['passed'], true), count($c2['discrepancies']));
qa_ok('a 500,000 over-allocation IS detected', $c2['passed'] === false,
    $c2['passed'] === true
        ? 'the check still passes — it cannot detect an allocation that does not tie to the pool'
        : 'detected');

$alloc->forceFill(['allocated_amount' => $orig])->saveQuietly();

qa_section('WHY — the check is an algebraic identity');
echo "  CamReconciliationService:277   landlord_unrecovered_amount := pool_actual − Σ allocated\n";
echo "  BooksReconciliationService:206 fail when |Σ allocated + landlord_unrecovered − pool_actual| > tol\n";
echo "  substituting:                  Σ + (actual − Σ) − actual  ≡  0    for every possible input\n";
$pool->refresh();
$summed = round((float) $pool->allocations()->sum('allocated_amount'), 2);
$unrec = round((float) $pool->landlord_unrecovered_amount, 2);
$actual = round((float) $pool->total_actual_expense, 2);
printf("  measured on pool #%d: %s + %s − %s = %s\n", $pool->id,
    number_format($summed, 2), number_format($unrec, 2), number_format($actual, 2),
    number_format($summed + $unrec - $actual, 2));
qa_eq('the expression is identically zero', 0.0, round($summed + $unrec - $actual, 2));

qa_section('and the field goes NEGATIVE when tenants are over-charged');
$over = CamExpensePool::where('landlord_unrecovered_amount', '<', -0.005)->get();
printf("  pools with a NEGATIVE 'landlord unrecovered' (= tenants billed more than actual cost): %d\n", $over->count());
foreach ($over as $p) {
    printf("    pool #%d %s (%d): actual %s, allocated %s, over-recovered %s\n", $p->id, $p->name, $p->period_year,
        number_format((float) $p->total_actual_expense, 2),
        number_format((float) $p->allocations()->sum('allocated_amount'), 2),
        number_format(abs((float) $p->landlord_unrecovered_amount), 2));
}
qa_ok('nothing in the reconcile flags a negative (= over-recovery)', $camCheck()['passed'] === true,
    'the operator is never told');

qa_summary();
