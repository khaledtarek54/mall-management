<?php

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\CreditNoteService;
use App\Services\VoidInvoiceService;
use App\Services\VoidPaymentService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

/**
 * GL integrity hardening — Phase 5: first-class void/cancel for AR documents. Now that a
 * finalized invoice/payment is locked from editing, voiding is the supported correction —
 * it reverses the AR and the ledger entry, with a reason, instead of a silent edit.
 */
afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('voids an issued invoice: status cancelled, balance zeroed, ledger entry reversed', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);
    $this->artisan('accounting:sync-ledger --all')->assertSuccessful();

    app(VoidInvoiceService::class)->void($invoice, 'billed twice');

    expect($invoice->fresh()->status)->toBe('cancelled')
        ->and((float) $invoice->fresh()->balance)->toBe(0.0)
        ->and($invoice->fresh()->notes)->toContain('billed twice');

    $this->artisan('accounting:sync-ledger --all')->assertSuccessful(); // sweep voids the entry
    expect(JournalEntry::where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->id)->where('status', 'void')->count())->toBe(1);
});

it('refuses to void an invoice that has captured payments', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease, ['total' => 5000, 'balance' => 5000]);
    $payment = Payment::create(['reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000, 'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString()]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);
    $invoice->recomputeTotals();

    expect(fn () => app(VoidInvoiceService::class)->void($invoice->fresh()))->toThrow(DomainException::class);
    expect($invoice->fresh()->status)->not->toBe('cancelled');
});

it('refuses to void an invoice already filed with ETA (eta_status = valid)', function () {
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), ['total' => 5000, 'balance' => 5000]);
    $invoice->forceFill(['eta_status' => 'valid'])->saveQuietly(); // filed tax invoice

    expect(fn () => app(VoidInvoiceService::class)->void($invoice->fresh()))->toThrow(DomainException::class);
    expect($invoice->fresh()->status)->not->toBe('cancelled');
});

it('voids a captured payment: status refunded, the invoice AR re-opens', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease, ['total' => 5000, 'balance' => 5000]);
    $payment = Payment::create(['reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000, 'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString()]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]);
    $invoice->recomputeTotals(); // paid 5000 → balance 0, status paid

    app(VoidPaymentService::class)->void($payment, 'chargeback');

    expect($payment->fresh()->status)->toBe('refunded')
        ->and((float) $invoice->fresh()->balance)->toBe(5000.0)  // AR re-opened
        ->and((float) $invoice->fresh()->paid_amount)->toBe(0.0);
});

it('voids an invoice through the edit-page action with a reason', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $asset = makeAsset();
    Filament::setTenant($asset);
    $invoice = makeInvoice(makeLease(makeUnit($asset)), ['total' => 5000, 'balance' => 5000]);

    Livewire::test(EditInvoice::class, ['record' => $invoice->getRouteKey()])
        ->callAction('void_invoice', ['reason' => 'entered in error'])
        ->assertHasNoActionErrors();

    expect($invoice->fresh()->status)->toBe('cancelled');
});

it('voids a payment through the edit-page action with a reason', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $asset = makeAsset();
    Filament::setTenant($asset);
    $lease = makeLease(makeUnit($asset));
    $invoice = makeInvoice($lease, ['total' => 5000, 'balance' => 5000]);
    $payment = Payment::create(['reference' => 'P-'.uniqid(), 'tenant_id' => $lease->tenant_id, 'amount' => 5000, 'method' => 'bank_transfer', 'status' => 'captured', 'payment_date' => now()->toDateString()]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => 5000]); // brings it into scope

    Livewire::test(EditPayment::class, ['record' => $payment->getRouteKey()])
        ->callAction('void_payment', ['reason' => 'refunded to card'])
        ->assertHasNoActionErrors();

    expect($payment->fresh()->status)->toBe('refunded');
});

it('voids a credit-applied invoice by UN-APPLYING the original note exactly once (lock-safe, idempotent, no double-count)', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    $invoice = makeInvoice($lease, ['total' => 5000, 'balance' => 5000]);
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'issued',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => 2000, 'vat_amount' => 0, 'total' => 2000, 'applied_amount' => 0, 'balance' => 2000, 'currency' => 'EGP',
    ]);
    app(CreditNoteService::class)->applyToInvoice($note, $invoice, 2000);
    expect((float) $invoice->fresh()->credit_applied_amount)->toBe(2000.0);

    $notesBefore = CreditNote::count();

    // Void, then re-void — the lock + re-read makes the second call a no-op (a racing second void
    // would otherwise un-apply twice; soft-deleted applications also make the un-apply idempotent).
    app(VoidInvoiceService::class)->void($invoice->fresh(), 'error');
    app(VoidInvoiceService::class)->void($invoice->fresh(), 'again');

    // No SECOND note is created (the old design double-counted the sales-return); the ORIGINAL note
    // is restored to available EXACTLY once — balance back to 2000, never 4000.
    expect(CreditNote::count())->toBe($notesBefore)
        ->and($invoice->fresh()->status)->toBe('cancelled')
        ->and((float) $invoice->fresh()->credit_applied_amount)->toBe(0.0)
        ->and($note->fresh()->status)->toBe('issued')
        ->and((float) $note->fresh()->balance)->toBe(2000.0)
        ->and(CreditNoteApplication::where('credit_note_id', $note->id)->count())->toBe(0);
});

it('grants the dedicated void permissions to accounting + super_admin but not viewer', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $has = fn (string $role, string $perm) => Role::findByName($role, 'web')->hasPermissionTo($perm);

    expect($has('accounting', 'invoices.void'))->toBeTrue()
        ->and($has('accounting', 'payments.void'))->toBeTrue()
        ->and($has('super_admin', 'invoices.void'))->toBeTrue()
        ->and($has('manager', 'invoices.void'))->toBeTrue()
        ->and($has('viewer', 'invoices.void'))->toBeFalse()
        ->and($has('viewer', 'payments.void'))->toBeFalse();
});
