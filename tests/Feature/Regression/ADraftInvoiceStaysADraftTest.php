<?php

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Support\InvoiceSettlement;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * SW-215 — writing a LINE onto a draft invoice issued it.
 *
 * `InvoiceItem::saved` calls `Invoice::syncTotalsFromItems()`, which calls `recomputeTotals()`,
 * whose auto-status block overrides anything outside its manual-override list — and `draft` was not
 * in that list. So the promotion fired on the only case that matters: a draft with no lines is not
 * a document anybody wants, and a draft is precisely an invoice WITH lines that has not been raised.
 *
 * Measured through the real create page, not the model: the operator picks **Draft** and the invoice
 * is stored **issued**. That put an unissued document in front of the tenant (the whole subject of
 * the draft-visibility invariant), on the books and in the GL — and `InvoiceForm` drops `draft` from
 * its options once the status has moved, so there was no way back.
 *
 * It was known and written down. `InvoiceSettlement`'s reason for refusing cash against a draft says
 * in writing that *"an unissued document becomes a live one without ever passing through
 * IssueInvoiceService"* — recorded as a hazard to route around rather than as a thing to fix.
 *
 * What must NOT change: only the STATUS is frozen. `paid_amount` and `balance` still recompute, and
 * issuing stays an ACT — `IssueInvoiceService` states the status at create, the panel's Select is
 * the other door — never a side effect of saving a line.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'DR']);
    $this->lease = makeLease(makeUnit($this->asset, ['code' => 'DR-01']));

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('keeps the status the operator picked, through the real create page', function () {
    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'lease_id' => $this->lease->id,
            'tenant_id' => $this->lease->tenant_id,
            'status' => 'draft',
            'issue_date' => now()->toDateString(),
            'due_date' => now()->addMonth()->toDateString(),
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(),
            'items' => [
                ['type' => 'base_rent', 'description' => 'Rent', 'amount' => 1000, 'vat_rate' => 14, 'total' => 1140],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    // Measured before the fix: 'issued'.
    expect(Invoice::latest('id')->first()->status)->toBe('draft');
});

it('still derives the totals — only the status is frozen', function () {
    // The half that must not move. Freezing the whole recompute would leave `subtotal`/`total`
    // disagreeing with the lines, which is the drift `syncTotalsFromItems()` exists to close.
    $invoice = Invoice::create([
        'asset_id' => $this->asset->id, 'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id, 'status' => 'draft',
        'issue_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
        'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 5000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 5000,
    ]);

    $fresh = $invoice->fresh();

    expect($fresh->status)->toBe('draft')
        ->and(round((float) $fresh->subtotal, 2))->toBe(5000.00)
        ->and(round((float) $fresh->total, 2))->toBe(5000.00)
        ->and(round((float) $fresh->balance, 2))->toBe(5000.00);
});

it('still promotes an ISSUED invoice through the derived ladder', function () {
    // The control, and it is what stops the fix being "freeze every status": an overdue invoice must
    // still become overdue on its own, or the collections surfaces go quiet.
    $invoice = Invoice::create([
        'asset_id' => $this->asset->id, 'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id, 'status' => 'issued',
        'issue_date' => now()->subMonths(2)->toDateString(), 'due_date' => now()->subMonth()->toDateString(),
        'period_start' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'period_end' => now()->subMonths(2)->endOfMonth()->toDateString(),
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 3000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 3000,
    ]);

    expect($invoice->fresh()->status)->toBe('overdue');
});

it('leaves a draft outside the settlement register, as it always was', function () {
    // The refusal that stood on this bug's back is unchanged — and it never needed it. Its first
    // reason is the real one: nothing was posted, so cash against a draft credits a receivable that
    // does not exist.
    $draft = Invoice::create([
        'asset_id' => $this->asset->id, 'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id, 'status' => 'draft',
        'issue_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
        'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000,
    ]);

    expect(InvoiceSettlement::accepts($draft))->toBeFalse()
        // Paired with the control, or a register that refused everything would satisfy this alone.
        ->and(InvoiceSettlement::accepts(tap($draft->replicate())->forceFill(['status' => 'issued'])))->toBeTrue();
});
