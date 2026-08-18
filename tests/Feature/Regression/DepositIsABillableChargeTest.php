<?php

/*
|--------------------------------------------------------------------------
| A security deposit is a charge on the tenant ledger (2026-08-18)
|--------------------------------------------------------------------------
| Voyager's model, adopted after an operator asked "the client doesn't know how he should pay" —
| and he was right, because there was nothing to pay. A deposit existed ONLY as a
| `DepositTransaction` recorded AFTER money arrived, so no document ever asked the tenant for it and
| the portal had to tell them to make a bank transfer and quote a reference.
|
| Billed, it behaves like every other charge: it ages, it reaches the statement and the collections
| screen, and it can be paid by card on the same rail as rent.
|
| **The GL is what makes it a deposit rather than income.** The `security_deposit` charge code posts
| to `deposits_held`, a LIABILITY:
|
|     billing   Dr Tenant Receivables   Cr Tenant Deposits Held
|     payment   Dr Bank                 Cr Tenant Receivables
|     ───────────────────────────────────────────────────────────
|     net       Dr Bank                 Cr Tenant Deposits Held   ← what a direct receipt posts
|
| So there is no double count and no second billing path: the invoice journalizer already credits
| whatever role a line's charge code names.
*/

use App\Models\ChargeCode;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\Accounting\LedgerPoster;
use App\Services\BillSecurityDepositService;
use App\Support\Vat;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\ChargeCodeSeeder;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(ChargeCodeSeeder::class);
    $this->seed(AccountingSeeder::class);
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, [
        'status' => 'active',
        'security_deposit' => 144000,
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2028-12-31',
    ]);
    $this->svc = app(BillSecurityDepositService::class);
});

it('bills the OUTSTANDING deposit, never the contractual figure', function () {
    depositMovement($this->lease, 'receipt', 100000);

    $invoice = $this->svc->bill($this->lease->fresh());

    // Billing 144,000 to a tenant who already paid 100,000 is how a landlord ends up holding —
    // and owing back — twice the deposit.
    expect((float) $invoice->total)->toBe(44000.0);
});

it('credits a LIABILITY, not revenue — the whole point of the model', function () {
    $invoice = $this->svc->bill($this->lease);
    app(LedgerPoster::class)->sync($invoice->fresh());

    $entry = JournalEntry::where('source_type', 'invoice')->where('source_id', $invoice->id)
        ->with('lines.account')->firstOrFail();

    $credited = $entry->lines->firstWhere('credit', '>', 0)->account;

    expect($credited->type)->toBe('liability')
        ->and(ChargeCode::roleFor('security_deposit'))->toBe('deposits_held');
});

it('charges no VAT — a deposit is a security, not a supply', function () {
    $invoice = $this->svc->bill($this->lease);

    expect((float) $invoice->vat_amount)->toBe(0.0)
        ->and(Vat::rateForType('security_deposit'))->toBe(0.0);
});

it('does not count an UNPAID deposit invoice as held', function () {
    $this->svc->bill($this->lease);

    // It is a receivable, not money in the bank. Treating it as held would refund at move-out what
    // was never received.
    expect($this->lease->fresh()->depositHeld())->toBe(0.0)
        ->and($this->lease->fresh()->depositShortfall())->toBe(144000.0);
});

it('counts it once the tenant pays, and closes the shortfall', function () {
    $invoice = $this->svc->bill($this->lease);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id, 'amount' => 144000, 'method' => 'bank_transfer',
        'status' => 'captured', 'payment_date' => now(), 'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 144000]);
    $payment->recomputeAllocatedInvoices();

    expect($this->lease->fresh()->depositHeld())->toBe(144000.0)
        ->and($this->lease->fresh()->depositShortfall())->toBe(0.0);
});

it('counts a PART payment as partly held', function () {
    $invoice = $this->svc->bill($this->lease);

    $payment = Payment::create([
        'tenant_id' => $this->tenant->id, 'amount' => 50000, 'method' => 'cash',
        'status' => 'captured', 'payment_date' => now(), 'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 50000]);
    $payment->recomputeAllocatedInvoices();

    // Derived from the line's settlement — a per-item balance is never stored.
    expect($this->lease->fresh()->depositHeld())->toBe(50000.0)
        ->and($this->lease->fresh()->depositShortfall())->toBe(94000.0);
});

it('refuses to bill a deposit that is already fully held', function () {
    depositMovement($this->lease, 'receipt', 144000);

    expect(fn () => $this->svc->bill($this->lease->fresh()))->toThrow(DomainException::class);
});

it('refuses to bill MORE than is outstanding', function () {
    expect(fn () => $this->svc->bill($this->lease, ['amount' => 200000]))->toThrow(DomainException::class);
});

it('ignores a cancelled deposit invoice', function () {
    $invoice = $this->svc->bill($this->lease);
    $invoice->update(['status' => 'cancelled', 'balance' => 0]);

    // A cancelled document claims nothing, so it can neither be held nor owed.
    expect($this->lease->fresh()->depositHeld())->toBe(0.0)
        ->and($this->lease->fresh()->depositShortfall())->toBe(144000.0);
});

it('adds a billed deposit to a directly-received one without double counting', function () {
    depositMovement($this->lease, 'receipt', 44000);

    $invoice = $this->svc->bill($this->lease->fresh());
    $payment = Payment::create([
        'tenant_id' => $this->tenant->id, 'amount' => (float) $invoice->total, 'method' => 'cash',
        'status' => 'captured', 'payment_date' => now(), 'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $invoice->total]);
    $payment->recomputeAllocatedInvoices();

    // Both rails feed one number. 44,000 received directly + 100,000 billed and paid.
    expect($this->lease->fresh()->depositHeld())->toBe(144000.0);
});
