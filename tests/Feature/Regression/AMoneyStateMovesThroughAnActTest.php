<?php

use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\DepositTransactions\Pages\EditDepositTransaction;
use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Models\CreditNote;
use App\Models\JournalEntry;
use App\Models\Payment;
use App\Services\Accounting\FiscalCalendar;
use App\Services\CapturePaymentService;
use App\Services\IssueInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **The last two bare-dropdown routes onto the books are acts now (SW-240 phases 1 and 4 + D-A).**
 *
 * Issuing a draft invoice put the document in front of the tenant, on the books and in the GL — and
 * it rode on the form's status Select, while the credit note beside it had an Issue button with its
 * own permission, a confirmation and a service. Capturing an initiated payment POSTS CASH
 * (`PaymentJournalizer` posts on the received set) and rode on the same kind of dropdown. And the
 * credit note itself had TWO doors: the act (gated on `credit_notes.issue`, confirmed, through
 * `CreditNoteService::issue()`) and the draft Select (gated on `credit_notes.edit` alone) — so an
 * operator without the issue right could issue by picking a value. Measured before closing: four
 * roles hold `credit_notes.edit` (`accounting` explicitly; `manager`, `mall_admin`, `super_admin`
 * via the blanket grant) and all four hold `credit_notes.issue`, so nothing lost reach.
 *
 * Each act is DRIVEN here through its real page — CLAUDE.md: an action's schema and closures only
 * evaluate on mount, so building one in a test proves nothing — and each is paired with the GL
 * assertion that is the whole point of it being an act: the books move when the act runs, and only
 * then.
 */
beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);
    // The chart and the posting map, so the acts' GL assertions measure a real post rather than a
    // journalizer returning no payload against an unmapped chart.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
    $this->asset = makeAsset(['code' => 'MA']);
    $this->lease = makeLease(makeUnit($this->asset, ['code' => 'MA-01']));

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The invoice's posted, non-void journal entry — the fact each act is asserted against. */
function postedEntryFor(object $record): ?JournalEntry
{
    return JournalEntry::query()
        ->where('source_type', $record->getMorphClass())
        ->where('source_id', $record->getKey())
        ->where('status', 'posted')
        ->first();
}

// ───────────────────────────── The invoice Issue act (D-A) ─────────────────────────────

it('issues a draft invoice through the act, and the books move with it', function () {
    $draft = makeInvoice($this->lease, ['status' => 'draft']);
    $draft->items()->create([
        'type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000,
        'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);

    // The control HALF, with the sweep already run: a draft posts nothing even when everything
    // around it is mapped and open — `InvoiceJournalizer` returns no payload for one. Without this
    // half, the assertion after the act proves the SWEEP works, not that the act changed anything.
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);
    expect(postedEntryFor($draft->refresh()))->toBeNull()
        ->and($draft->status)->toBe('draft');

    Livewire::test(EditInvoice::class, ['record' => $draft->id])
        ->callAction('issue')
        ->assertHasNoActionErrors();

    $issued = $draft->refresh();

    // The realtime sync rides the queue and a test transaction never commits, so the sweep is the
    // honest reader here — the same idiom as AMoneyDocumentSaysWhatItDidToTheBooksTest. What the
    // ACT is proved to do is change what the books demand: sweep before, nothing; act; sweep
    // after, an AR entry.
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    // `overdue`, not `issued` — the fixture's due date is long past, and `raise()` walks the
    // derived ladder after the save (the review caught its first version claiming this while
    // calling nothing). This assertion is what proves the recompute runs: delete that call and a
    // past-due draft sits on `issued` until the nightly scan, and this line goes red.
    expect($issued->status)->toBe('overdue')
        ->and(postedEntryFor($issued))->not->toBeNull();
});

it('refuses to issue a draft with no lines, in the reader\'s words', function () {
    $empty = makeInvoice($this->lease, ['status' => 'draft']);
    $empty->items()->delete();

    // The service is the gate; the act shows its sentence as a toast. Asserted on the service so
    // the refusal cannot pass because the toast machinery swallowed it.
    expect(fn () => app(IssueInvoiceService::class)->raise($empty->refresh()))
        ->toThrow(DomainException::class, __('admin.errors.issue_invoice_no_lines'));
});

it('refuses to issue what is not a draft', function () {
    $issued = makeInvoice($this->lease);

    expect(fn () => app(IssueInvoiceService::class)->raise($issued))
        ->toThrow(DomainException::class, __('admin.errors.issue_invoice_not_draft'))
        // …and the act hides where it cannot act, so the header carries no dead button.
        ->and(Livewire::test(EditInvoice::class, ['record' => $issued->id])
            ->instance()->headerActs()['issue']->isVisible())->toBeFalse();
});

// ───────────────────────────── The payment Capture act (Phase 1) ─────────────────────────────

/** An initiated payment allocated to an invoice — allocated, or its own Edit page cannot see it. */
function initiatedPaymentOn(object $invoice): Payment
{
    $payment = Payment::create([
        'tenant_id' => $invoice->tenant_id,
        'payment_date' => now()->toDateString(),
        'amount' => (float) $invoice->total,
        'method' => 'bank_transfer',
        'status' => 'initiated',
        'currency' => 'EGP',
    ]);
    $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $invoice->total]);

    return $payment;
}

it('captures an initiated payment through the act, posting the cash and paying the invoice', function () {
    $invoice = makeInvoice($this->lease);
    $payment = initiatedPaymentOn($invoice);

    // The control half, sweep already run: initiated money is not received — no cash entry, and
    // the invoice unpaid.
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);
    $invoice->recomputeTotals();
    expect(postedEntryFor($payment))->toBeNull()
        ->and((float) $invoice->refresh()->paid_amount)->toBe(0.0);

    Livewire::test(EditPayment::class, ['record' => $payment->id])
        ->callAction('capture')
        ->assertHasNoActionErrors();

    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    expect($payment->refresh()->status)->toBe('captured')
        ->and(postedEntryFor($payment))->not->toBeNull()
        // The allocation starts counting the moment the money is real — the four-channel rule.
        ->and((float) $invoice->refresh()->paid_amount)->toBe((float) $invoice->total);
});

it('refuses a capture that would settle an invoice another channel already paid', function () {
    // THE REVIEW'S ONE FATAL, as a regression. An initiated allocation is invisible to every
    // settlement sum — `RECEIVED_STATUSES` filters it out of `recomputeTotals()` and the
    // over-allocation guard alike — so in the days between a gateway session dying and the
    // operator capturing by hand, the invoice can be settled by any other channel. The first cut
    // of `CapturePaymentService` was a bare status flip: capturing then relieved AR a SECOND
    // time, `paid_amount` doubled on the invoice, and the excess buried itself as negative AR —
    // the four-channel invariant CLAUDE.md records as having happened once already. The service
    // now takes the canonical locks and runs both payment guards with the flip visible to their
    // sums, refusing rather than clamping — unlike the gateway, no money is in hand yet.
    $invoice = makeInvoice($this->lease);
    $stranded = initiatedPaymentOn($invoice);

    // The other channel settles the invoice while the initiated payment sits forgotten.
    settleInvoiceInFull($invoice);
    expect((float) $invoice->refresh()->paid_amount)->toBe((float) $invoice->total);

    expect(fn () => app(CapturePaymentService::class)->capture($stranded))
        ->toThrow(DomainException::class);

    // The refusal left the world alone: still initiated, and the invoice settled exactly once.
    expect($stranded->refresh()->status)->toBe('initiated')
        ->and((float) $invoice->refresh()->paid_amount)->toBe((float) $invoice->total);
});

it('refuses to capture what already moved', function () {
    $payment = settleInvoiceInFull(makeInvoice($this->lease));

    expect(fn () => app(CapturePaymentService::class)->capture($payment))
        ->toThrow(DomainException::class, __('admin.errors.capture_not_initiated'));

    // …and the act hides on a captured payment.
    Livewire::test(EditPayment::class, ['record' => $payment->id])
        ->assertActionHidden('capture');
});

// ───────────────────────────── One door to an issued credit note (Phase 4) ─────────────────────────────

it('shows a saved draft credit note\'s status as a display, with the issue act as its one door', function () {
    $draft = CreditNote::create([
        'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id,
        'status' => 'draft',
        'issue_date' => now()->toDateString(),
        'reason' => 'adjustment',
        'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'balance' => 100,
    ]);

    $page = Livewire::test(EditCreditNote::class, ['record' => $draft->id]);

    expect($page->instance()->form->getComponent('status')->isDisabled())->toBeTrue();

    // The one remaining door works — driven, not assumed, because an action's closures only
    // evaluate on mount.
    $page->callAction('issue')->assertHasNoActionErrors();

    expect($draft->refresh()->status)->toBe('issued');
});

it('locks the credit note\'s reason once issued — a classification on a delivered document', function () {
    $issued = CreditNote::create([
        'tenant_id' => $this->lease->tenant_id,
        'lease_id' => $this->lease->id,
        'status' => 'issued',
        'issue_date' => now()->toDateString(),
        'reason' => 'discount',
        'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'balance' => 100,
    ]);

    $form = fn (CreditNote $note) => Livewire::test(EditCreditNote::class, ['record' => $note->id])
        ->instance()->form;

    expect($form($issued)->getComponent('reason')->isDisabled())->toBeTrue()
        // The memo beside it stays open — Yardi's line is memo open, classification closed, and a
        // fix that froze the notes would be the over-lock.
        ->and($form($issued)->getComponent('reason_notes')->isDisabled())->toBeFalse();
});

it('keeps the cheque clearance date typeable until the money is received', function () {
    // The review's dead-end finding: an INITIATED cheque payment — reachable from this form's own
    // create-time status choice — clears LATER, and locking the date at exists left no way to ever
    // record when. It is the one instrument field that is a fact that arrives rather than
    // identity: open until received or reversed (type the date, then Capture), locked after.
    $invoice = makeInvoice($this->lease);
    $initiated = initiatedPaymentOn($invoice);

    $clearance = fn (Payment $p) => Livewire::test(EditPayment::class, ['record' => $p->id])
        ->instance()->form->getComponent('cheque_clearance_date');

    expect($clearance($initiated)->isDisabled())->toBeFalse()
        // …while the instrument's IDENTITY locks at exists even on the initiated payment.
        ->and(Livewire::test(EditPayment::class, ['record' => $initiated->id])
            ->instance()->form->getComponent('cheque_number')->isDisabled())->toBeTrue();

    $captured = settleInvoiceInFull(makeInvoice($this->lease));

    expect($clearance($captured)->isDisabled())->toBeTrue();
});

// ─────────────── The flag that decides whether the pot was ever booked (Phase 3) ───────────────

it('refuses to flip the opening-balance flag on a drawn-on deposit receipt', function () {
    // The model gap the §17 audit found: the receipt freeze named every column that changes what
    // the pot is MADE OF and missed the one that changes whether the pot was ever BOOKED — the
    // opening flag suppresses the receipt's `Dr Cash / Cr Deposits Held` entirely, so flipping it
    // on a drawn-on receipt voids the posted credit while the applications' debits stand: the GL
    // pot goes negative by the receipt's full value, the amount-edit hole worn as a checkbox.
    $receipt = depositMovement($this->lease, 'receipt', 100000);
    depositMovement($this->lease, 'refund', 10000);

    expect(fn () => $receipt->refresh()->update(['is_opening_balance' => true]))
        ->toThrow(DomainException::class);
});

it('renders the flag disabled on the drawn-on receipt\'s own form', function () {
    // The FORM half, which the review proved was covered by nothing: reverting the field to the
    // cancelled-only lock left every test green, because the model regression above drives
    // `update()` and the conformance gate's deposit fixture is deliberately UNDRAWN. Same
    // predicate on both layers means proving both layers.
    $receipt = depositMovement($this->lease, 'receipt', 70000);
    depositMovement($this->lease, 'refund', 5000);

    $form = Livewire::test(
        EditDepositTransaction::class,
        ['record' => $receipt->fresh()->id],
    )->instance()->form;

    expect($form->getComponent('is_opening_balance')->isDisabled())->toBeTrue()
        ->and($form->getComponent('amount')->isDisabled())->toBeTrue()
        // …while the rail pair stays open by design — the registry's reason, not an accident.
        ->and($form->getComponent('method')->isDisabled())->toBeFalse();
});

it('leaves the flag correctable on an undrawn receipt — the over-lock control', function () {
    // The model's own design: a receipt keyed wrongly must stay fixable until something depends on
    // it. A freeze that reached back before the draw would break the cutover correction the flag
    // exists for.
    $lease2 = makeLease(makeUnit($this->asset, ['code' => 'MA-02']));
    $receipt = depositMovement($lease2, 'receipt', 50000);

    $receipt->update(['is_opening_balance' => true]);

    expect($receipt->refresh()->is_opening_balance)->toBeTrue();
});
