<?php

use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Models\CreditNote;
use App\Models\Payment;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * GL integrity hardening — Phase 1: finalized money documents are immutable in the
 * admin form. Once an invoice / credit note is past `draft` (and any payment once it
 * exists), its money-affecting fields are locked (`->disabled()`), so a user can't
 * silently rewrite a posted AR/GL document — corrections go through void / re-issue /
 * credit note. Metadata (status, notes) and legitimate operations (payment
 * re-allocation) stay open. Matches the existing VendorBill/Expense $locked convention.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('locks an issued invoice\'s money fields but keeps status editable', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset))); // makeInvoice → 'issued'

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertFormFieldIsDisabled('lease_id')
        ->assertFormFieldIsDisabled('tenant_id')
        ->assertFormFieldIsDisabled('issue_date')
        ->assertFormFieldIsDisabled('items')
        // SW-240: the status is a DISPLAY on any saved invoice. This line asserted it enabled
        // ("dispute/cancel transitions still allowed") — but cancel had already moved to the Void
        // act, disputing moved to the per-LINE act, and issuing a draft is the Issue act now, so
        // nothing was left for the control to do except offer mistakes.
        ->assertFormFieldIsDisabled('status');
});

it('leaves a draft invoice fully editable', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset)), ['status' => 'draft']);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->assertFormFieldIsEnabled('items')
        ->assertFormFieldIsEnabled('issue_date')
        ->assertFormFieldIsEnabled('lease_id');
});

it('locks a captured payment\'s money fields but keeps allocations editable', function () {
    $lease = makeLease(makeUnit($this->asset));
    $invoice = makeInvoice($lease, ['subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'balance' => 5000]);
    $payment = Payment::create([
        'reference' => 'PAY-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000,
        'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString(),
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]); // brings it into scope

    Livewire::test(EditPayment::class, ['record' => $payment->getRouteKey()])
        ->assertFormFieldIsDisabled('amount')
        ->assertFormFieldIsDisabled('payment_date')
        ->assertFormFieldIsDisabled('method')
        ->assertFormFieldIsDisabled('tenant_id')
        ->assertFormFieldIsEnabled('allocations') // re-allocation is a legitimate op
        // SW-240: this asserted status enabled for the "captured→failed chargeback" — a reversal
        // that has gone through the reason-gated Void act since 2026-08-28, so the comment was
        // already describing a door the panel had closed. The one transition the dropdown still
        // performed (initiated→captured, which posts cash) is the Capture act now.
        ->assertFormFieldIsDisabled('status');
});

it('locks an issued credit note but leaves a draft one editable', function () {
    $lease = makeLease(makeUnit($this->asset));
    $base = [
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'issue_date' => now()->toDateString(),
        'reason' => 'adjustment', 'subtotal' => 500, 'vat_amount' => 0, 'total' => 500,
        'applied_amount' => 0, 'balance' => 500, 'currency' => 'EGP',
    ];
    $issued = CreditNote::create([...$base, 'status' => 'issued']);
    $draft = CreditNote::create([...$base, 'status' => 'draft']);

    Livewire::test(EditCreditNote::class, ['record' => $issued->getRouteKey()])
        ->assertFormFieldIsDisabled('tenant_id')
        ->assertFormFieldIsDisabled('invoice_id')
        ->assertFormFieldIsDisabled('issue_date')
        ->assertFormFieldIsDisabled('items');

    Livewire::test(EditCreditNote::class, ['record' => $draft->getRouteKey()])
        ->assertFormFieldIsEnabled('items')
        ->assertFormFieldIsEnabled('issue_date');
});

// --- C1: the status→draft un-lock bypass must be closed at BOTH layers ---

it('rejects reverting a finalized invoice to draft at the model layer', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset))); // issued

    expect(fn () => $invoice->update(['status' => 'draft']))->toThrow(DomainException::class);
    expect($invoice->fresh()->status)->toBe('issued'); // not persisted
});

it('rejects reverting a finalized credit note to draft at the model layer', function () {
    $lease = makeLease(makeUnit($this->asset));
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'issue_date' => now()->toDateString(),
        'reason' => 'adjustment', 'subtotal' => 500, 'vat_amount' => 0, 'total' => 500,
        'applied_amount' => 0, 'balance' => 500, 'currency' => 'EGP', 'status' => 'issued',
    ]);

    expect(fn () => $note->update(['status' => 'draft']))->toThrow(DomainException::class);
    expect($note->fresh()->status)->toBe('issued');
});

it('does not offer draft as a status option for a finalized invoice (UI validation)', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset))); // issued

    // 'draft' is dropped from the options, so Filament's in: rule rejects it — the save
    // is refused with a form error before the money fields could ever re-open.
    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->fillForm(['status' => 'draft'])
        ->call('save')
        ->assertHasFormErrors(['status']);

    expect($invoice->fresh()->status)->toBe('issued');
});

// --- Defense-in-depth: model-layer guards on the truly-immutable fields ---

it('freezes a finalized invoice\'s GL-identity fields but allows forward transitions', function () {
    $invoice = makeInvoice(makeLease(makeUnit($this->asset))); // issued

    expect(fn () => $invoice->update(['issue_date' => now()->subMonths(3)->toDateString()]))
        ->toThrow(DomainException::class); // period is immutable
    expect(fn () => $invoice->fresh()->update(['tenant_id' => makeTenant()->id]))
        ->toThrow(DomainException::class); // AR dimension is immutable

    $invoice->fresh()->update(['status' => 'disputed']); // a forward status change is fine
    expect($invoice->fresh()->status)->toBe('disputed');
});

it('freezes a captured payment\'s amount/date but allows capture + chargeback transitions', function () {
    $lease = makeLease(makeUnit($this->asset));
    $payment = Payment::create([
        'reference' => 'PAY-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000,
        'method' => 'card', 'status' => 'initiated', 'payment_date' => now()->toDateString(),
    ]);

    $payment->update(['status' => 'captured']); // the capture transition must NOT be blocked
    expect($payment->fresh()->status)->toBe('captured');

    expect(fn () => $payment->fresh()->update(['amount' => 6000]))->toThrow(DomainException::class);
    expect(fn () => $payment->fresh()->update(['payment_date' => now()->subDay()->toDateString()]))
        ->toThrow(DomainException::class);

    $payment->fresh()->update(['status' => 'failed']); // captured→failed chargeback is fine
    expect($payment->fresh()->status)->toBe('failed');
});

it('freezes a finalized credit note\'s target fields but allows applying it', function () {
    $lease = makeLease(makeUnit($this->asset));
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'issue_date' => now()->toDateString(),
        'reason' => 'adjustment', 'subtotal' => 500, 'vat_amount' => 0, 'total' => 500,
        'applied_amount' => 0, 'balance' => 500, 'currency' => 'EGP', 'status' => 'issued',
    ]);

    expect(fn () => $note->update(['issue_date' => now()->subMonth()->toDateString()]))
        ->toThrow(DomainException::class);

    $note->fresh()->update(['status' => 'applied', 'applied_amount' => 100, 'balance' => 400]);
    expect($note->fresh()->status)->toBe('applied'); // derived fields stay writable
});
