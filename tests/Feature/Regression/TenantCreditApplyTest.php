<?php

use App\Support\MorphMap;
use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Models\TenantCreditApplication;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\ApplyTenantCreditService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\VoidInvoiceService;
use App\Services\VoidPaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Applying a tenant's on-account CREDIT to an invoice. It posts its OWN Dr Unearned / Cr AR entry
 * dated at application time (a new GL source), NOT by re-deriving the original — possibly closed
 * period — receipt. That is the correct design after the first attempt (pivot re-shuffle) was found
 * to strand the GL. These pin the AR reduction, the dated-now GL posting, the GL↔AR tie-out through
 * the real sweep, reversal, caps, isolation, and the refund guard.
 */
beforeEach(function () {
    // Auto-apply OFF: these exercise the MANUAL apply path, where an operator chooses the
    // invoice and sometimes the amount. With the automatic trigger on, the credit would be
    // consumed by the invoice's own creation before the test could apply it deliberately.
    app(\App\Settings\BillingSettings::class)->auto_apply_tenant_credit = false;

    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    app(FiscalCalendar::class)->ensureYear((int) now()->subMonthNoOverflow()->year);
});

/** An invoice with a single VAT-exempt rent line so the GL math is clean. */
function creditInvoice($lease, float $rent, ?string $issueDate = null): Invoice
{
    $issueDate ??= now()->toDateString();
    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => $issueDate, 'due_date' => now()->addDays(7)->toDateString(),
        'subtotal' => $rent, 'vat_amount' => 0, 'total' => $rent, 'balance' => $rent, 'paid_amount' => 0,
    ]);
    $invoice->items()->create([
        'description' => 'Rent', 'type' => 'base_rent', 'amount' => $rent, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $rent,
    ]);

    return $invoice;
}

/** Overpay $invoice by $surplus, leaving $surplus as the tenant's on-account credit. */
function overpayLeavingCredit(Invoice $invoice, float $surplus, ?string $payDate = null): Payment
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id, 'amount' => round((float) $invoice->total + $surplus, 2),
        'currency' => 'EGP', 'method' => 'cash', 'status' => 'captured', 'payment_date' => $payDate ?? now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $invoice->total]);
    $invoice->recomputeTotals();

    return $payment;
}

it('applies credit, reduces the invoice, and ties the GL out to AR through the real posting', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);

    $invA = creditInvoice($lease, 10000);
    $payment = overpayLeavingCredit($invA, 5000);   // 5,000 credit on account
    $invB = creditInvoice($lease, 5000);

    $applied = app(ApplyTenantCreditService::class)->applyToInvoice($invB);

    expect($applied)->toBe(5000.0)
        ->and((float) $invB->fresh()->balance)->toBe(0.0)
        ->and($invB->fresh()->status)->toBe('paid')
        ->and($tenant->creditBalance())->toBe(0.0);

    // Post every source through the real journalizers, then assert the books tie out.
    foreach ([$invA, $payment, $invB, TenantCreditApplication::where('invoice_id', $invB->id)->first()] as $doc) {
        app(LedgerPoster::class)->sync($doc->fresh());
    }

    $app = TenantCreditApplication::where('invoice_id', $invB->id)->first();
    $entry = JournalEntry::where('source_type', MorphMap::alias(TenantCreditApplication::class))
        ->where('source_id', $app->id)->where('status', 'posted')->with('lines')->first();

    expect($entry)->not->toBeNull()                                   // it posted its own entry …
        ->and((float) $entry->lines->sum('debit'))->toBe(5000.0)     // Dr Unearned 5,000
        ->and((float) $entry->lines->sum('credit'))->toBe(5000.0);   // Cr AR 5,000 (balanced)

    // AR control account == the invoice subledger (both zero here). No drift.
    $tie = app(BooksReconciliationService::class)->glTieOut();
    expect($tie['ar']['delta'])->toBe(0.0);
});

it('posts the applied credit through the real accounting:sync-ledger sweep — not just LedgerPoster', function () {
    // The strict GL invariant: at least one test per source must drive the REAL service AND the
    // REAL sweep (the case above calls LedgerPoster::sync() directly, which only proves the
    // journalizer's arithmetic — it never exercises the discovery/dispatch layer). A regression in
    // the sweep wiring would strand this source exactly as it once stranded the SLA penalty, so we
    // prove the sweep finds and posts it, then tie the books out. @see SlaPenaltyLedgerDispatchTest
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);

    $invA = creditInvoice($lease, 10000);
    overpayLeavingCredit($invA, 5000);       // 5,000 credit on account
    $invB = creditInvoice($lease, 5000);

    app(ApplyTenantCreditService::class)->applyToInvoice($invB);
    $app = TenantCreditApplication::where('invoice_id', $invB->id)->firstOrFail();

    // Nothing has posted the application yet — the sweep is the only production path exercised here.
    expect(JournalEntry::where('source_type', MorphMap::alias(TenantCreditApplication::class))->exists())
        ->toBeFalse('precondition: the credit application has not been posted yet');

    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $entry = JournalEntry::where('source_type', MorphMap::alias(TenantCreditApplication::class))
        ->where('source_id', $app->id)->where('status', 'posted')->with('lines')->first();

    expect($entry)->not->toBeNull('the sweep did not post the applied credit')
        ->and((float) $entry->lines->sum('debit'))->toBe(5000.0)     // Dr Unearned 5,000
        ->and((float) $entry->lines->sum('credit'))->toBe(5000.0)    // Cr AR 5,000
        // …and the books tie out through the same sweep that posted the invoice + payment + application.
        ->and(app(BooksReconciliationService::class)->glTieOut()['ar']['delta'])->toBe(0.0);
});

it('applies a credit from a CLOSED-period overpayment without stranding the GL (the fixed bug)', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);

    // An overpayment received (and posted) LAST month, then that month is closed.
    $lastMonth = now()->subMonthNoOverflow()->startOfMonth();
    $invA = creditInvoice($lease, 10000, $lastMonth->toDateString());
    $payment = overpayLeavingCredit($invA, 5000, $lastMonth->copy()->addDays(3)->toDateString());
    app(LedgerPoster::class)->sync($invA->fresh());
    app(LedgerPoster::class)->sync($payment->fresh());
    AccountingPeriod::forDate($lastMonth)->update(['status' => 'closed']); // last month sealed

    // Apply the old credit to THIS month's invoice — the correcting entry is dated today (open).
    $invB = creditInvoice($lease, 5000);
    $applied = app(ApplyTenantCreditService::class)->applyToInvoice($invB);
    $app = TenantCreditApplication::where('invoice_id', $invB->id)->first();
    app(LedgerPoster::class)->sync($app->fresh());

    expect($applied)->toBe(5000.0)
        ->and($app->entry_date->isSameMonth(now()))->toBeTrue()      // dated TODAY, not the closed month
        ->and((float) $invB->fresh()->balance)->toBe(0.0)            // AR relieved …
        ->and(JournalEntry::where('source_type', MorphMap::alias(TenantCreditApplication::class))
            ->where('source_id', $app->id)->where('status', 'posted')->exists())->toBeTrue(); // … and the GL followed
});

it('reverses an applied credit — the invoice re-opens and the credit returns', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invA = creditInvoice($lease, 10000);
    overpayLeavingCredit($invA, 5000);
    $invB = creditInvoice($lease, 5000);

    app(ApplyTenantCreditService::class)->applyToInvoice($invB);
    expect((float) $invB->fresh()->balance)->toBe(0.0)->and($tenant->creditBalance())->toBe(0.0);

    $reversed = app(ApplyTenantCreditService::class)->reverseForInvoice($invB);

    expect($reversed)->toBe(5000.0)
        ->and((float) $invB->fresh()->balance)->toBe(5000.0)         // re-opened
        ->and($tenant->creditBalance())->toBe(5000.0)               // credit returned
        ->and(TenantCreditApplication::where('invoice_id', $invB->id)->exists())->toBeFalse(); // soft-deleted
});

it('caps the applied credit at the invoice balance, leaving the rest on account', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invA = creditInvoice($lease, 10000);
    overpayLeavingCredit($invA, 8000);            // 8,000 credit
    $invB = creditInvoice($lease, 3000);          // only 3,000 owed

    $applied = app(ApplyTenantCreditService::class)->applyToInvoice($invB);

    expect($applied)->toBe(3000.0)
        ->and((float) $invB->fresh()->balance)->toBe(0.0)
        ->and($tenant->creditBalance())->toBe(5000.0); // 8,000 − 3,000
});

it('throws when the tenant has no credit to apply', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    app(ApplyTenantCreditService::class)->applyToInvoice(creditInvoice($lease, 5000));
})->throws(DomainException::class);

it('scopes credit to the property — cannot settle another property\'s invoice', function () {
    $tenant = makeTenant();
    $leaseA = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    overpayLeavingCredit(creditInvoice($leaseA, 10000), 5000);          // credit at property A

    // A DIFFERENT property's invoice for the same tenant.
    $leaseB = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invOther = creditInvoice($leaseB, 5000);

    app(ApplyTenantCreditService::class)->applyToInvoice($invOther);
})->throws(DomainException::class); // no credit available in property B

it('blocks refunding a receipt whose surplus has been applied as credit', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invA = creditInvoice($lease, 10000);
    $payment = overpayLeavingCredit($invA, 5000);
    app(ApplyTenantCreditService::class)->applyToInvoice(creditInvoice($lease, 5000)); // apply the 5,000

    expect(fn () => app(VoidPaymentService::class)->void($payment, 'refund'))
        ->toThrow(DomainException::class);
});

it('cannot apply the same cross-property surplus twice (global backstop cap)', function () {
    // A single receipt split across TWO malls reports its whole surplus under both properties' scopes
    // (a scoping imprecision in creditBalance). The unscoped backstop must stop the second draw.
    $tenant = makeTenant();
    $leaseX = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $leaseY = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $invX = creditInvoice($leaseX, 3000);
    $invY = creditInvoice($leaseY, 3000);

    $payment = Payment::create([
        'tenant_id' => $tenant->id, 'amount' => 11000, 'currency' => 'EGP',
        'method' => 'cash', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invX->id, ['allocated_amount' => 3000]); // cross-property allocation
    $payment->invoices()->attach($invY->id, ['allocated_amount' => 3000]); // (only reachable off-UI)
    $invX->recomputeTotals();
    $invY->recomputeTotals();

    // Draw the whole 5,000 surplus at property X.
    $newX = creditInvoice($leaseX, 5000);
    expect(app(ApplyTenantCreditService::class)->applyToInvoice($newX))->toBe(5000.0);

    // The same surplus must NOT be applicable again at Y — the tenant's TOTAL credit is now 0.
    app(ApplyTenantCreditService::class)->applyToInvoice(creditInvoice($leaseY, 5000));
})->throws(DomainException::class);

it('blocks refunding a receipt whose surplus was spent, even when another mall holds credit', function () {
    // The refund guard must be scoped to THIS receipt's property — a global balance lets unrelated
    // credit at another mall mask that this receipt's own surplus was already applied.
    $tenant = makeTenant();
    $leaseA = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    $leaseB = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);

    // P1 at mall A, surplus 5,000 → applied to an A invoice (A credit now 0).
    $p1 = overpayLeavingCredit(creditInvoice($leaseA, 10000), 5000);
    app(ApplyTenantCreditService::class)->applyToInvoice(creditInvoice($leaseA, 5000));

    // P2 at mall B, surplus 5,000, untouched — this is the credit that masks the global balance.
    overpayLeavingCredit(creditInvoice($leaseB, 10000), 5000);

    // Refunding P1 must still be blocked: ITS surplus (mall A) is already spent.
    expect(fn () => app(VoidPaymentService::class)->void($p1, 'refund'))
        ->toThrow(DomainException::class);
});

it('voiding an invoice paid by applied tenant credit reverses the credit (never orphaned)', function () {
    $tenant = makeTenant();
    $lease = makeLease(makeUnit(makeAsset()), $tenant, ['status' => 'active']);
    overpayLeavingCredit(creditInvoice($lease, 10000), 5000);
    $invB = creditInvoice($lease, 5000);
    app(ApplyTenantCreditService::class)->applyToInvoice($invB);
    expect((float) $invB->fresh()->balance)->toBe(0.0)->and($tenant->creditBalance())->toBe(0.0);

    // Applied tenant credit is reversible non-cash — void proceeds (not blocked as "captured cash")
    // and the cancel hook returns the credit; the applications must NOT strand on the void invoice.
    app(VoidInvoiceService::class)->void($invB, 'wrong invoice');

    expect($invB->fresh()->status)->toBe('cancelled')
        ->and(TenantCreditApplication::where('invoice_id', $invB->id)->exists())->toBeFalse()
        ->and($tenant->creditBalance())->toBe(5000.0); // credit came back
});
