<?php

require __DIR__.'/boot.php';
use App\Models\CamExpensePool;

echo "pool  year  status       actual        Σ allocated   unrecovered   Σ+unrec-actual\n";
foreach (CamExpensePool::with('allocations')->get() as $p) {
    $sum = round((float) $p->allocations->sum('allocated_amount'), 2);
    $un = round((float) $p->landlord_unrecovered_amount, 2);
    $act = round((float) $p->total_actual_expense, 2);
    printf("#%-4d %-5d %-12s %13s %13s %13s %13s\n", $p->id, $p->period_year, $p->status,
        number_format($act, 2), number_format($sum, 2), number_format($un, 2), number_format($sum + $un - $act, 2));
}
$neg = CamExpensePool::whereRaw('landlord_unrecovered_amount < -0.005')->get();
echo "\npools where the landlord OVER-recovered (negative unrecovered): ".$neg->count()."\n";
foreach ($neg as $p) {
    printf("  #%d %s (%d): over-recovered %s\n", $p->id, $p->name, $p->period_year, number_format(abs((float) $p->landlord_unrecovered_amount), 2));
}
