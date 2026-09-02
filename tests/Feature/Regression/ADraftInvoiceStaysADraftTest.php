<?php

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Models\AccountingPeriod;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Accounting\FiscalCalendar;
use App\Services\VoidInvoiceService;
use App\Support\InvoiceSettlement;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

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
    // The chart and the posting map, because `SealedPeriod` FAILS OPEN when the journalizer cannot
    // answer — deliberately, so an incomplete accounting setup never blocks ordinary work. Without
    // them the sealed-period case below passes for the wrong reason: no refusal, and no guard either.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
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

    $invoice = Invoice::latest('id')->first();

    // Measured before the fix: 'issued'.
    //
    // **The line count is asserted, because "a line lands" IS the mechanism.** Without it this case
    // passes just as happily on an invoice that never got one — and then it is measuring nothing,
    // which is the same "assert the sweep found something" rule the conformance gates follow.
    expect($invoice->status)->toBe('draft')
        ->and($invoice->items)->toHaveCount(1);
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

it('refuses to issue a draft into a period that has closed since', function () {
    // **The hole the freeze opened, and both guards written for it miss.** `SealedPeriod::guard()`
    // looked up the document's POSTED entry and returned when there was none — and a draft has none,
    // because `InvoiceJournalizer` returns null for one. `GuardsPostingDate` is `isDirty($column)`
    // by design, and issuing a draft moves no date.
    //
    // Before the freeze a panel draft could not survive its first line, so it always posted in the
    // period it was born in. Now it can outlive a close: raise a draft dated in March, close March,
    // issue it in May. The save committed, `SyncDocumentToLedger` then refused at
    // `assertOpenPeriodFor()` and only LOGGED — leaving AR on the document and nothing in the
    // ledger, i.e. `billing:reconcile --deep` permanently red and `atriom:preflight` blocking the
    // next deploy for a reason nothing on screen explains.
    app(FiscalCalendar::class)->ensureYear(2026);

    $invoice = Invoice::create([
        'asset_id' => $this->asset->id, 'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id, 'status' => 'draft',
        'issue_date' => '2026-03-10', 'due_date' => '2026-03-20',
        'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 9000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 9000,
    ]);

    // By DATE RANGE: `accounting_periods` has no year/month columns — it is `period_no` plus
    // `starts_on`/`ends_on`, which is what `forDate()` reads. A `where('year', …)` matches nothing,
    // closes nothing, and the case then passes for the wrong reason: no refusal AND no guard.
    $march = fn (string $status) => AccountingPeriod::query()
        ->whereDate('starts_on', '<=', '2026-03-10')
        ->whereDate('ends_on', '>=', '2026-03-10')
        ->update(['status' => $status]);

    expect($march('closed'))->toBe(1, 'no accounting period covers 2026-03-10, so nothing was closed');

    expect(fn () => $invoice->fresh()->update(['status' => 'issued']))
        ->toThrow(DomainException::class);

    // …and the control, or a guard that refused every issue would satisfy that alone: an OPEN
    // period still issues.
    $march('open');

    $invoice->fresh()->update(['status' => 'issued']);

    expect($invoice->fresh()->status)->toBe('issued');
});

it('lets an abandoned draft be cancelled, and frees its month for billing again', function () {
    // The other half the freeze exposed. An abandoned draft had NO way out: the void service refused
    // it with a message naming a delete that does not exist (`Invoice` is `#[NeverDeletable]`, there
    // is no DeleteAction on the resource, and the bulk one is hidden panel-wide), and the form
    // removes `cancelled` from its options. Meanwhile `MonthlyBillingService`'s already-billed probe
    // counted the draft — so that lease-month could never be billed again, reported as
    // `skipped: already_billed` and indistinguishable from a lease that was billed correctly.
    $invoice = Invoice::create([
        'asset_id' => $this->asset->id, 'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id, 'status' => 'draft',
        'issue_date' => now()->toDateString(), 'due_date' => now()->addMonth()->toDateString(),
        'period_start' => now()->startOfMonth()->toDateString(), 'period_end' => now()->endOfMonth()->toDateString(),
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0,
    ]);

    InvoiceItem::create([
        'invoice_id' => $invoice->id, 'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 4000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 4000,
    ]);

    // It is CANCELLED, not voided: nothing was posted, so there is no reversal and no number burnt.
    app(VoidInvoiceService::class)->void($invoice->fresh(), 'Raised by mistake.');

    expect($invoice->fresh()->status)->toBe('cancelled')
        // The reason is recorded, because a reversal that explains nothing is the thing
        // `ReversalReason` exists to stop.
        ->and(Activity::query()->where('subject_id', $invoice->id)->where('event', 'cancelled')->exists())->toBeTrue();
});
