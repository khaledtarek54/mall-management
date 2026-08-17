<?php

/*
|--------------------------------------------------------------------------
| Cancelling an invoice with captured cash — every path, not just the service
|--------------------------------------------------------------------------
| `VoidInvoiceService` has always refused to void an invoice with a captured payment allocated to
| it: the money would stop being receivable without ever being returned. The invoice form's status
| Select offered `cancelled` as a plain option and walked straight past that.
|
| Measured before the fix — an invoice with a captured 10,000 payment, cancelled from the form:
|
|   invoice        status=cancelled   balance forced to 0
|   payment        status=captured    still allocated 10,000
|   tenant credit  0
|
| The tenant's 10,000 was neither receivable nor owed back. It had disappeared from every
| operator-visible surface while the cash sat in the general ledger.
|
| The guard now lives on the model, so the form, the API, a console command and a tinker session
| all hit it, and it reuses `capturedCashPaid()` — the same predicate the service uses — so the two
| cannot drift apart.
*/

use App\Filament\Admin\Resources\Invoices\Schemas\InvoiceForm;
use App\Models\CreditNote;
use App\Models\Payment;
use App\Services\CreditNoteService;
use App\Services\VoidInvoiceService;

function paidInvoiceFixture(float $amount = 10000): array
{
    $lease = makeLease(makeUnit(makeAsset()), makeTenant());

    $invoice = makeInvoice($lease, [
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount,
        'balance' => $amount, 'status' => 'issued',
    ]);

    $payment = Payment::create([
        'reference' => 'PAY-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => $amount,
        'currency' => 'EGP', 'method' => 'bank_transfer', 'status' => 'captured',
        'payment_date' => now(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => $amount]);
    $invoice->recomputeTotals();

    return [$invoice->fresh(), $payment, $lease->tenant];
}

it('refuses a direct status change to cancelled when captured cash is allocated', function () {
    [$invoice] = paidInvoiceFixture();

    // The form path: set the status and save. This used to succeed.
    $invoice->status = 'cancelled';

    expect(fn () => $invoice->save())->toThrow(DomainException::class);

    // Nothing moved — the invoice is still a live, paid receivable.
    $after = $invoice->fresh();
    expect($after->status)->toBe('paid')
        ->and((float) $after->paid_amount)->toBe(10000.0);
});

it('agrees with VoidInvoiceService, which refuses the same case', function () {
    [$invoice] = paidInvoiceFixture();

    expect(fn () => app(VoidInvoiceService::class)->void($invoice, 'test'))
        ->toThrow(DomainException::class);
});

it('does not offer cancelled as a pickable status on the form', function () {
    // Belt and braces: the model is the gate, but the UI should not invite the action either —
    // cancelling is the outcome of the Void action, which takes a reason and audits it.
    $options = InvoiceForm::class;

    expect(file_get_contents((new ReflectionClass($options))->getFileName()))
        ->toContain("unset(\$options['cancelled'])");
});

it('still allows the cancel once the cash is gone', function () {
    // The guard must not trap an invoice forever: refund the payment and the void proceeds.
    [$invoice, $payment] = paidInvoiceFixture();

    $payment->forceFill(['status' => 'refunded'])->save();
    $invoice->fresh()->recomputeTotals();

    $voided = app(VoidInvoiceService::class)->void($invoice->fresh(), 'refunded then voided');

    expect($voided->status)->toBe('cancelled')
        ->and((float) $voided->balance)->toBe(0.0);
});

it('still allows the cancel when settlement was reversible non-cash', function () {
    // Applied credit notes are reversible, so they must NOT block — capturedCashPaid() nets them
    // out, and the cancel returns the credit. Only real cash refuses.
    $lease = makeLease(makeUnit(makeAsset()), makeTenant());
    $invoice = makeInvoice($lease, [
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000, 'status' => 'issued',
    ]);

    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id,
        'invoice_id' => $invoice->id,
        'issue_date' => now()->toDateString(),
        'reason' => 'adjustment',
        'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000,
        'status' => 'draft',
    ]);
    app(CreditNoteService::class)->issue($note);
    app(CreditNoteService::class)->applyToInvoice($note->fresh(), $invoice->fresh());

    $settled = $invoice->fresh();
    expect((float) $settled->capturedCashPaid())->toBe(0.0);

    $voided = app(VoidInvoiceService::class)->void($settled, 'credited then voided');
    expect($voided->status)->toBe('cancelled');
});
