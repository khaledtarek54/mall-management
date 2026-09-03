<?php

/*
|--------------------------------------------------------------------------
| Regression — 2026-07-11 adversarial QA sweep survivors
|--------------------------------------------------------------------------
| Eight business-logic defects found by reading integration seams to break
| them, each confirmed by 2 independent skeptics. One guard per fix.
*/

use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\Payments\Pages\CreatePayment;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\Accounting\LedgerPoster;
use App\Services\CamReconciliationService;
use App\Services\CreditNoteService;
use App\Services\LateFeeService;
use App\Services\LeaseTerminationService;
use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

afterEach(fn () => Carbon::setTestNow());

// ── Finding: CAM zero true-up must not emit a phantom 0.00 charge ─────────────
it('settles a zero CAM true-up without creating a phantom charge', function () {
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    // actual == estimated → true_up = 0.00
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 40000, 'total_estimated_collected' => 40000, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $allocation = $svc->bill($pool->allocations()->sole());

    expect($allocation->status)->toBe('billed')
        ->and($allocation->billed_charge_id)->toBeNull()
        ->and(Charge::count())->toBe(0)
        ->and(Invoice::count())->toBe(0);
});

// ── Finding: CAM recovery basis frozen once any allocation is billed ──────────
it('blocks editing the CAM recovery basis after an allocation is billed', function () {
    Carbon::setTestNow('2027-01-15');
    $asset = makeAsset();
    makeLease(makeUnit($asset, ['area_sqm' => 100]), makeTenant());
    $pool = CamExpensePool::create([
        'asset_id' => $asset->id, 'period_year' => 2026,
        'total_actual_expense' => 50000, 'total_estimated_collected' => 30000, 'status' => 'draft',
    ]);
    $svc = app(CamReconciliationService::class);
    $svc->generateAllocations($pool);
    $svc->bill($pool->allocations()->sole());

    expect(fn () => $pool->update(['total_actual_expense' => 20000]))
        ->toThrow(DomainException::class);

    // A non-money field (notes) still saves fine (refresh to drop the rejected dirty attr).
    $pool->refresh();
    $pool->update(['notes' => 'ok']);
    expect($pool->fresh()->notes)->toBe('ok');
});

// ── Finding: annual CAM-recovery invoice must not trip the monthly guard ──────
it('still bills base rent for a month when the lease has an annual CAM-recovery invoice that month', function () {
    Carbon::setTestNow('2026-01-20');
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant(), [
        'commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31',
        'base_rent_monthly' => 10000, 'service_charge_monthly' => 0,
    ]);
    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Rent', 'type' => 'base_rent',
        'amount' => 10000, 'frequency' => 'monthly', 'is_active' => true, 'start_date' => '2025-01-01',
    ]);
    // An annual CAM-recovery invoice for this lease dated Jan 1 – Dec 31 (period_start in January).
    $annual = makeInvoice($lease, [
        'period_start' => '2026-01-01', 'period_end' => '2026-12-31',
        'subtotal' => 5000, 'total' => 5000, 'balance' => 5000,
    ]);
    // Use the REAL recovery item type the CamReconciliationService writes (cam_recovery) — 'cam' is
    // not a valid invoice_items.type (MySQL enum) and never reaches production; the monthly billing
    // probe excludes a lease's regular invoice from the special types [percentage_rent, cam_recovery,
    // cam_admin_fee], so a faithful fixture must use one of those.
    $annual->items()->create(['type' => 'cam_recovery', 'description' => 'CAM recovery', 'amount' => 5000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 5000]);

    app(MonthlyBillingService::class)->generateForLease($lease, CarbonImmutable::parse('2026-01-01'));

    // The base rent for January must have been billed (a NEW monthly invoice with a base_rent line).
    $billedRent = Invoice::where('lease_id', $lease->id)
        ->whereHas('items', fn ($q) => $q->where('type', 'base_rent'))
        ->exists();
    expect($billedRent)->toBeTrue();
});

// ── Finding: late fee must re-check balance/status inside the lock ────────────
it('does not apply a late fee if the invoice was paid between snapshot and lock', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'due_date' => '2026-01-01', 'status' => 'overdue', 'balance' => 10000,
    ]);
    // Stale snapshot (as the batch scan captured it: overdue, balance 10000)…
    $stale = Invoice::find($invoice->id);

    // …then a payment settles it before the per-invoice lock. A REAL receipt, not typed columns:
    // `recomputeTotals()` derives `paid_amount` and `balance` from the four settlement channels, so
    // `['status' => 'paid', 'balance' => 0]` with nothing behind it is restored to a balance of
    // 10,000 — a state the auto-status block cannot produce. The old guard matched three status
    // strings and so was satisfied by the word `paid` alone; the guard asks whether money is
    // actually owed, which is the question a late fee turns on.
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'amount' => (float) $invoice->total, 'method' => 'cash',
        'status' => 'captured', 'payment_date' => '2026-01-05',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $invoice->total]);
    $invoice->fresh()->recomputeTotals();

    $applied = app(LateFeeService::class)->applyTo($stale);

    expect($applied)->toBeFalse()
        ->and(lateFeeItems($invoice)->count())->toBe(0);
});

// ── Finding: lease termination must not cancel an ETA-filed invoice ───────────
it('leaves an ETA-filed invoice untouched when terminating a lease', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), [
        'commencement_date' => '2025-01-01', 'expiry_date' => '2027-12-31',
    ]);
    $filed = makeInvoice($lease, ['status' => 'issued', 'balance' => 5000, 'paid_amount' => 0, 'eta_status' => 'valid']);
    $plain = makeInvoice($lease, ['status' => 'issued', 'balance' => 3000, 'paid_amount' => 0, 'eta_status' => null]);

    // Both invoices cover the helper's default February period and the lease ends 31 January, so
    // neither was earned. The only difference left between them is the filing — which is the whole
    // claim. Without the explicit date, "period already earned" would refuse both and the test would
    // pass without ever exercising the ETA guard.
    app(LeaseTerminationService::class)->terminate($lease, [
        'termination_date' => '2026-01-31',
        'cancel_open_invoices' => true,
    ]);

    expect($filed->fresh()->status)->toBe('issued')      // tax-filed → preserved
        ->and($plain->fresh()->status)->toBe('cancelled'); // ordinary → cancelled
});

// ── Finding: GL entry_date drift is not reachable for invoices ────────────────
// The adversarial sweep flagged LedgerPoster::matches() ignoring entry_date (a
// source whose date moved would keep a stale GL date). On my own review that is
// NOT reachable for invoices: a finalized invoice's issue_date is immutable
// (Invoice model guard) and a draft invoice is never GL-posted — the mutable
// window and the posted window never overlap. This guard pins that protection.
it('keeps a finalized invoice issue_date immutable so its GL entry_date cannot go stale', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), ['status' => 'issued', 'issue_date' => '2026-03-10']);

    expect(fn () => $invoice->update(['issue_date' => '2026-04-25']))
        ->toThrow(DomainException::class);
});

// ── Finding: payment auto-suggest must be property-scoped like the picker ─────
it('does not auto-suggest an out-of-scope invoice when allocating a payment', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $propA = makeAsset(['code' => 'PROP-A']);
    $propB = makeAsset(['code' => 'PROP-B']);
    $tenant = makeTenant(['name' => 'Shared Retailer']);
    $leaseA = makeLease(makeUnit($propA, ['code' => 'A-1']), $tenant);
    $leaseB = makeLease(makeUnit($propB, ['code' => 'B-1']), $tenant);
    $invA = makeInvoice($leaseA, ['status' => 'issued', 'balance' => 4000, 'due_date' => '2026-02-01']);
    $invB = makeInvoice($leaseB, ['status' => 'issued', 'balance' => 4000, 'due_date' => '2026-01-01']);

    // Restricted manager who can only see property A.
    $user = makeUser('manager', [$propA->id]);
    $this->actingAs($user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($propA);

    $page = Livewire::test(CreatePayment::class)
        ->set('data.tenant_id', $tenant->id)
        ->set('data.amount', 99999);

    $allocations = $page->get('data')['allocations'] ?? [];
    $ids = collect($allocations)->pluck('invoice_id')->filter()->all();

    expect($ids)->not->toContain($invB->id) // property B invoice must NOT be suggested
        ->and($ids)->toContain($invA->id);  // property A invoice IS suggested

    Filament::setTenant(null, isQuiet: true);
});

// ── Finding: a finalized credit note's balance is not clobbered on a plain edit ─
it('keeps a finalized credit note balance = total - applied after an unrelated edit-save', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset), makeTenant());
    $invoice = makeInvoice($lease, ['status' => 'issued', 'balance' => 8000]);

    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'invoice_id' => $invoice->id, 'lease_id' => $lease->id,
        'number' => 'CN-TEST-1', 'status' => 'issued', 'issue_date' => '2026-03-01', 'reason' => 'adjustment',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'applied_amount' => 0, 'balance' => 5000,
    ]);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($asset);

    // Mount the edit form (captures fill-time balance = 5000)…
    $page = Livewire::test(EditCreditNote::class, ['record' => $note->getRouteKey()]);
    // …then the note is partially applied out-of-band (balance -> 2000)…
    app(CreditNoteService::class)->applyToInvoice($note, $invoice, 3000);
    expect($note->fresh()->balance)->toEqual(2000);
    // …then a plain edit-save (e.g. adjusting the notes) must NOT restore the stale 5000.
    $page->call('save');

    $note->refresh();
    expect((float) $note->balance)->toBe((float) ($note->total - $note->applied_amount))
        ->and((float) $note->balance)->toBe(2000.0);

    Filament::setTenant(null, isQuiet: true);
});
