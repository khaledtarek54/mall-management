<?php

/** One racer. argv: <scenario> <startAtUnixMs> <workerLabel> [ids...] */
require __DIR__.'/boot.php';
use App\Models\Expense;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderLabour;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Payment;
use App\Models\PostDatedCheque;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Services\PostDatedChequeService;
use Carbon\CarbonImmutable;

$scenario = getenv('RACE_SCENARIO');
$startAt = (float) getenv('RACE_START');
$label = getenv('RACE_LABEL');
$args = explode(',', (string) getenv('RACE_ARGS'));

// spin until the shared start instant so both workers hit the DB together
while (microtime(true) * 1000 < $startAt) {
    usleep(200);
}

$out = fn (string $r) => file_put_contents(__DIR__."/race_{$scenario}_{$label}.out", $r);

try {
    switch ($scenario) {
        case 'lease':
            $unitId = (int) $args[0];
            $tenantId = (int) $args[1];
            $l = app(LeaseCreationService::class)->create([
                'tenant_mode' => 'existing', 'tenant_id' => $tenantId,
                'lease' => ['unit_id' => $unitId, 'commencement_date' => '2026-09-01', 'term_months' => 12,
                    'base_rent_monthly' => 50000, 'service_charge_monthly' => 0],
            ]);
            $out("OK lease #{$l->id}");
            break;

        case 'billing':
            $leaseId = (int) $args[0];
            $r = app(MonthlyBillingService::class)->generateForLease(
                Lease::with('charges')->findOrFail($leaseId), CarbonImmutable::parse('2026-09-01'));
            $out('OK '.json_encode(['status' => $r['status'] ?? null, 'reason' => $r['reason'] ?? null, 'invoice' => $r['invoice']->id ?? null]));
            break;

            // SW-212 — two writers on ONE work order's cost object. Worker A books an expense, worker
            // B applies an SLA penalty to a bill; both then recompute. Under the old read-modify-write
            // the loser's aggregate came from its own pre-wait snapshot and the last write won, so one
            // of the two costs vanished. `recomputeCosts()` computes inside its UPDATE now, so there is
            // no window to lose it in.
            // SW-212 — a LOST UPDATE on a work order's cost object.
            //
            // **A must have a cost of its OWN, and it must book it after B has finished.** Three
            // earlier versions of this scenario measured NOTHING, and the third is the instructive one:
            // A opened a transaction, read (fixing its REPEATABLE READ snapshot), waited, and
            // recomputed — and its SQL trace showed four aggregate SELECTs and **no UPDATE at all**.
            // A's stale aggregate equalled A's stale in-memory row byte for byte, so `getDirty()` was
            // empty and `Model::performUpdate()` short-circuited before issuing anything. B's correct
            // figure stood, the run read clean, and the scenario was green against the defect.
            // (The other two: both workers inserting and recomputing back to back just serialised; both
            // inserting inside one snapshot window collided on the document number instead, so the
            // UNIQUE index refused one and the cost race never ran — the same accident CLAUDE.md
            // records for the unit double-booking.)
            //
            // So: A opens its snapshot, waits for B to commit, books its OWN labour, and recomputes —
            // unambiguously the last writer, with something to write. Under the old read-modify-write
            // its stale service figure was simply ABSENT from the SET list (Laravel omits an unchanged
            // column), so the row was stored `labour 1500 + material 0 + service 3000` with a TOTAL of
            // 2500. The failure is not a bucket losing a bill; it is **a row that no longer adds up**,
            // on the column `costVariance()` reads.
            //
            // It is intermittent (2 runs in 3), and what hides it is the FK lock: a child insert takes
            // a shared lock on the parent, so whenever B lands last B repairs A's damage.
            //
            // seed: one 1,000 expense on the order. truth after both: labour 1,500 + service 3,000.
        case 'wo_cost':
            $orderId = (int) $args[0];
            $order = FacilityWorkOrder::findOrFail($orderId);

            if ($label === 'A') {
                DB::transaction(function () use ($order) {
                    DB::table('expenses')->where('facility_work_order_id', $order->id)->count();
                    usleep(1_000_000);

                    FacilityWorkOrderLabour::create([
                        'facility_work_order_id' => $order->id,
                        'hours' => 5, 'hourly_rate' => 300, 'cost' => 1500,
                        'worked_on' => '2026-09-01', 'notes' => 'RACE-A',
                    ]);
                });
            } else {
                usleep(400_000);
                Expense::create([
                    'asset_id' => $order->asset_id, 'facility_work_order_id' => $order->id,
                    'category' => 'maintenance', 'description' => 'RACE-B', 'amount' => 2000,
                    'expense_date' => '2026-09-01', 'status' => 'recorded', 'paid_from' => 'cash',
                ]);
            }

            $f = $order->fresh();
            $out(sprintf('OK lab=%s mat=%s svc=%s TOTAL=%s (truth 4500.00)',
                $f->act_labour_cost, $f->act_material_cost, $f->act_service_cost, $f->act_total_cost));
            break;

        case 'payment':
            $invId = (int) $args[0];
            $amount = (float) $args[1];
            $inv = Invoice::findOrFail($invId);
            $p = Payment::create(['tenant_id' => $inv->tenant_id, 'amount' => $amount, 'payment_date' => '2026-08-20',
                'method' => 'cash', 'status' => 'captured', 'reference' => 'RACE-'.$label.'-'.uniqid()]);
            DB::transaction(function () use ($p, $inv, $amount) {
                $p->invoices()->sync([$inv->id => ['allocated_amount' => $amount]]);
                $p->recomputeAllocatedInvoices();
                $p->assertInvoicesNotOverAllocated([$inv->id]);
            });
            $out("OK payment #{$p->id} allocated {$amount}");
            break;

        case 'guardprobe':
            // Replicates LeaseCreationService::create()'s transaction SHAPE exactly:
            //   plain read (tenant)  →  Unit::lockForUpdate()  →  isActivelyLeased() guard
            // A inserts a lease and commits; B must then see it.
            $unitId = (int) $args[0];
            $tenantId = (int) $args[1];
            DB::transaction(function () use ($unitId, $tenantId, $label, $out) {
                $tenant = Tenant::findOrFail($tenantId);        // ← the FIRST plain read of the txn
                if ($label === 'B') {
                    usleep(1_500_000);
                }      // let A get in first
                $unit = Unit::with('asset')->lockForUpdate()->findOrFail($unitId);
                $seen = $unit->isActivelyLeased();
                $committed = Lease::where('unit_id', $unitId)->where('status', 'active')->count();
                if ($label === 'A') {
                    Lease::create(['tenant_id' => $tenantId, 'unit_id' => $unitId,
                        'reference' => 'GP-A-'.uniqid(), 'status' => 'active', 'currency' => 'EGP',
                        'commencement_date' => '2026-09-01', 'expiry_date' => '2027-08-31', 'term_months' => 12,
                        'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
                        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
                    $out('A: guard saw isActivelyLeased='.var_export($seen, true)." (count {$committed}) → inserted its lease");
                } else {
                    usleep(500_000);
                    $again = $unit->fresh()->isActivelyLeased();
                    $out('B: guard saw isActivelyLeased='.var_export($seen, true)." (count {$committed}); after refresh=".var_export($again, true));
                }
            });
            break;

        case 'guardfix':
            // Same shape, but the guard uses a LOCKING read, which bypasses the REPEATABLE READ
            // snapshot and always sees the latest committed row.
            $unitId = (int) $args[0];
            $tenantId = (int) $args[1];
            DB::transaction(function () use ($unitId, $tenantId, $label, $out) {
                $tenant = Tenant::findOrFail($tenantId);
                if ($label === 'B') {
                    usleep(1_500_000);
                }
                $unit = Unit::with('asset')->lockForUpdate()->findOrFail($unitId);
                $snapshotRead = Lease::where('unit_id', $unitId)->where('status', 'active')->count();
                $lockingRead = Lease::where('unit_id', $unitId)->where('status', 'active')->lockForUpdate()->count();
                if ($label === 'A') {
                    Lease::create(['tenant_id' => $tenantId, 'unit_id' => $unitId,
                        'reference' => 'GF-A-'.uniqid(), 'status' => 'active', 'currency' => 'EGP',
                        'commencement_date' => '2026-09-01', 'expiry_date' => '2027-08-31', 'term_months' => 12,
                        'base_rent_monthly' => 50000, 'service_charge_monthly' => 0, 'has_marketing_levy' => false,
                        'billing_frequency' => 'monthly', 'payment_terms_days' => 7, 'escalation_type' => 'none']);
                    $out("A: snapshot={$snapshotRead} locking={$lockingRead} → inserted");
                } else {
                    $out("B: snapshot read sees {$snapshotRead} · LOCKING read sees {$lockingRead}");
                }
            });
            break;

        case 'payprobe':
            // Two receipts for the FULL balance of one invoice, with distinct references so the
            // document-number unique index cannot be what stops the second one.
            $invId = (int) $args[0];
            $amount = (float) $args[1];
            $inv = Invoice::findOrFail($invId);
            $p = new Payment(['tenant_id' => $inv->tenant_id, 'amount' => $amount, 'payment_date' => date('Y-m-d'),
                'method' => 'cash', 'status' => 'captured']);
            $p->reference = 'PAYPROBE-'.$label.'-'.uniqid();
            $p->save();
            if ($label === 'B') {
                usleep(1_800_000);
            }   // let A commit first
            DB::transaction(function () use ($p, $inv, $amount, $label, $out) {
                $p->invoices()->sync([$inv->id => ['allocated_amount' => $amount]]);
                $snapshot = (float) DB::table('invoice_payment')
                    ->join('payments', 'payments.id', '=', 'invoice_payment.payment_id')
                    ->where('invoice_payment.invoice_id', $inv->id)
                    ->whereIn('payments.status', Payment::RECEIVED_STATUSES)
                    ->sum('invoice_payment.allocated_amount');
                $p->assertInvoicesNotOverAllocated([$inv->id]);
                $out("{$label}: guard PASSED — allocated {$amount}; guard's view of total allocated = {$snapshot}");
            });
            break;

        case 'pdc':
            $id = (int) $args[0];
            $c = app(PostDatedChequeService::class)->clear(
                PostDatedCheque::findOrFail($id),
                User::where('email', 'admin@mall.test')->firstOrFail(), date('Y-m-d'));
            $out("OK cheque #{$c->id} cleared");
            break;
    }
} catch (Throwable $e) {
    $out('REFUSED '.get_class($e).': '.mb_substr($e->getMessage(), 0, 140));
}
