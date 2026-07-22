<?php

use App\Filament\Admin\RelationManagers\CreditNoteApplicationsRelationManager;
use App\Models\CreditNote;
use App\Models\CreditNoteApplication;
use App\Models\Invoice;
use App\Services\Accounting\FiscalCalendar;
use App\Services\CreditNoteService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * Credit Notes UX — applied_amount is now a verifiable breakdown (the applications relation manager)
 * with a per-row un-apply, the granular counterpart to the all-or-nothing "reverse". Pins the new
 * reverseApplication() service logic + the un-apply authz gate.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

function cnUxInvoice($lease, float $amount): Invoice
{
    $invoice = makeInvoice($lease, [
        'status' => 'issued', 'issue_date' => now()->toDateString(), 'due_date' => now()->addDays(7)->toDateString(),
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount, 'balance' => $amount, 'paid_amount' => 0,
    ]);
    $invoice->items()->create([
        'description' => 'Rent', 'type' => 'base_rent', 'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
    ]);

    return $invoice;
}

function cnUxNote($lease, float $net): CreditNote
{
    $note = CreditNote::create([
        'tenant_id' => $lease->tenant_id, 'lease_id' => $lease->id, 'status' => 'issued',
        'issue_date' => now()->toDateString(), 'reason' => 'adjustment',
        'subtotal' => $net, 'vat_amount' => 0, 'total' => $net, 'applied_amount' => 0, 'balance' => $net, 'currency' => 'EGP',
    ]);
    $note->items()->create(['description' => 'Overcharge', 'amount' => $net, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $net]);

    return $note;
}

it('reverseApplication un-applies ONE invoice, leaving the note\'s other applications intact', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $invA = cnUxInvoice($lease, 5000);
    $invB = cnUxInvoice($lease, 5000);
    $note = cnUxNote($lease, 8000); // issued, balance 8000

    $svc = app(CreditNoteService::class);
    $svc->applyToInvoice($note, $invA->fresh());          // 5000 → invA (fully paid)
    $svc->applyToInvoice($note->fresh(), $invB->fresh()); // 3000 → invB (note drained)
    expect((float) $note->fresh()->applied_amount)->toBe(8000.0);

    $appA = CreditNoteApplication::where('credit_note_id', $note->id)->where('invoice_id', $invA->id)->firstOrFail();
    $reversed = $svc->reverseApplication($appA);

    expect($reversed)->toBe(5000.0)
        ->and((float) $invA->fresh()->balance)->toBe(5000.0)         // invA re-opened
        ->and((float) $note->fresh()->applied_amount)->toBe(3000.0)  // only invB's 3,000 still applied
        ->and($note->fresh()->status)->toBe('issued')               // available again (balance > 0)
        // invA's application row is gone; invB's is untouched.
        ->and(CreditNoteApplication::where('invoice_id', $invA->id)->whereNull('deleted_at')->exists())->toBeFalse()
        ->and(CreditNoteApplication::where('invoice_id', $invB->id)->whereNull('deleted_at')->exists())->toBeTrue();
});

it('canUnapply requires the apply permission (a viewer cannot un-apply)', function () {
    $lease = makeLease(makeUnit(makeAsset()), makeTenant(), ['status' => 'active']);
    $inv = cnUxInvoice($lease, 5000);
    $note = cnUxNote($lease, 3000);
    app(CreditNoteService::class)->applyToInvoice($note, $inv->fresh());
    $app = CreditNoteApplication::where('credit_note_id', $note->id)->firstOrFail();

    $this->actingAs(makeUser('viewer')); // holds credit_notes.view, NOT .apply
    expect(CreditNoteApplicationsRelationManager::canUnapply($app->fresh()))->toBeFalse();

    $this->actingAs(makeUser('super_admin'));
    expect(CreditNoteApplicationsRelationManager::canUnapply($app->fresh()))->toBeTrue();
});
