<?php

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Payments\Pages\EditPayment;
use App\Models\Invoice;
use App\Models\Payment;
use App\Services\DisputeInvoiceItemService;
use App\Support\ValueSets;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **A status past the first one is the outcome of an ACT, never a value you pick.**
 *
 * Two statuses were still offered on the two money documents an operator touches most, and neither
 * had an act behind it. This is the fourth and fifth time the same reasoning has had to be applied
 * to `InvoiceForm` — `cancelled`, `written_off` and `credited` were each removed for it, and two of
 * their own comments record that the rule "was never generalised" to the next one.
 *
 * **Invoice `disputed`.** Nothing in `app/` or `database/` writes `invoices.status = 'disputed'`:
 * the dropdown was the only door. Picking it stopped collections on the WHOLE document —
 * `Invoice::NOT_CHASEABLE` is `['disputed', 'paid']`, so the overdue scan, the dunning sweep and the
 * late-fee sweep all skip it — with no reason stored and no audit act, and `CreditNoteService` then
 * refused to credit the invoice at all. The proper act was already built and already on this
 * record's own page: `DisputeInvoiceItemService` flags one LINE, REQUIRES a reason, records who and
 * when, and takes only that line's outstanding out of the late-fee base. Its docblock says the
 * header status is the wrong tool in so many words — an invoice is rarely disputed in full, so the
 * argument is over the service charge while the rent on the same document is undisputed.
 *
 * **Payment `reconciled` and `settled`.** `payments.status` documents them as "matched in
 * accounting" and "final", and bank reconciliation — `MatchBankStatementLineService` — writes a
 * `BankMatch` row and never touches the column. Nothing writes either value; nothing reads them
 * apart from membership in `RECEIVED_STATUSES`, which `captured` already satisfies. So an operator
 * marked a receipt "Reconciled", believed it had been matched to a bank statement, and it meant
 * nothing to any consumer.
 *
 * **What must NOT change, and is asserted here as a control each time:** the vocabulary stays. Both
 * columns still ACCEPT these values — legacy rows, imports and `RECEIVED_STATUSES` all depend on it
 * — so a record already carrying one must still render and still save. Removing the option without
 * keeping that fallback would refuse every save of such a record on a field the operator never
 * touched, which is the trap `InvoiceForm`'s own comment names.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'ST']);
    $this->lease = makeLease(makeUnit($this->asset, ['code' => 'ST-01']));

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The status Select's option KEYS, read off the real mounted Edit page. */
function statusOptionsOn(string $page, int $recordId): array
{
    return array_map('strval', array_keys(
        Livewire::test($page, ['record' => $recordId])
            ->instance()
            ->form
            ->getComponent('status')
            ->getOptions()
    ));
}

it('does not offer to dispute a whole invoice from its status field', function () {
    $invoice = makeInvoice($this->lease);

    $options = statusOptionsOn(EditInvoice::class, $invoice->id);

    // The refusal.
    expect($options)->not->toContain('disputed')
        // …paired with a control, or a Select that offered nothing at all would satisfy it. An
        // issued invoice keeps exactly one target: the derived ladder it is already on.
        ->and($options)->toContain('issued')
        // The three statuses removed before this one stay removed — a regression on any of them
        // reads identically to this bug and would otherwise be caught by nothing here.
        ->and($options)->not->toContain('cancelled')
        ->and($options)->not->toContain('written_off')
        ->and($options)->not->toContain('credited');
});

it('still renders an invoice that already carries the disputed status, and offers a way back', function () {
    // Legacy and imported rows: `NOT_CHASEABLE` still honours the value, so the column still
    // accepts it and the record must stay saveable. Filament validates a Select by resolving the
    // submitted value's label, so a record whose own status is not in the options is refused on
    // every save — on a field the operator never touched.
    $invoice = makeInvoice($this->lease, ['status' => 'disputed']);

    $options = statusOptionsOn(EditInvoice::class, $invoice->id);

    expect($options)->toContain('disputed')
        // The way OUT. `written_off` was a one-way door once, and that is recorded in
        // `InvoiceForm` as the reason not to remove an option without leaving an exit.
        ->and($options)->toContain('issued');
});

it('offers no bank-reconciliation status on a received payment', function () {
    // Built through `settleInvoiceInFull()` rather than by hand: `Payment` is property-scoped
    // through its allocated invoices, so a receipt attached to nothing is not in scope and the
    // Edit page 404s — the test would fail for a reason unrelated to the status field.
    $payment = settleInvoiceInFull(makeInvoice($this->lease));

    $options = statusOptionsOn(EditPayment::class, $payment->id);

    expect($options)->not->toContain('reconciled')
        ->and($options)->not->toContain('settled')
        // The control: a received payment still has its own state, and the reversals still are not
        // offered here — they go through the reason-gated Void action.
        ->and($options)->toContain('captured')
        ->and($options)->not->toContain('refunded')
        ->and($options)->not->toContain('bounced')
        // …and it cannot be walked back to un-received from a dropdown.
        ->and($options)->not->toContain('initiated');
});

it('still renders a payment that already carries a reconciled status', function () {
    $payment = settleInvoiceInFull(makeInvoice($this->lease));
    $payment->forceFill(['status' => 'reconciled'])->saveQuietly();

    expect(statusOptionsOn(EditPayment::class, $payment->id))->toContain('reconciled');
});

it('keeps counting the retired words as money received', function () {
    // The vocabulary is not what was wrong — offering it as a decision was. `RECEIVED_STATUSES`
    // must go on treating a `reconciled` receipt as cash in, or removing the OPTION would quietly
    // un-pay every invoice such a payment settles.
    expect(Payment::RECEIVED_STATUSES)->toContain('reconciled')
        ->and(Payment::RECEIVED_STATUSES)->toContain('settled')
        ->and(ValueSets::allowed('payments', 'status'))->toContain('reconciled');
});

it('leaves the line-level dispute act — the one with a reason — on the record page', function () {
    // The replacement has to be reachable, or this change removes a capability instead of moving
    // it. Both halves: raising a dispute and resolving one.
    $invoice = makeInvoice($this->lease);

    $acts = array_keys(
        Livewire::test(EditInvoice::class, ['record' => $invoice->id])->instance()->headerActs()
    );

    expect($acts)->toContain('disputeLine')->and($acts)->toContain('resolveDispute');
});

it('records who disputed a line and why, which the header status never did', function () {
    // The point of the move, stated as behaviour rather than as a comment: the act that replaced
    // the dropdown captures a reason, and refuses without one.
    $invoice = makeInvoice($this->lease);
    $item = $invoice->items()->create([
        'type' => 'service_charge',
        'description' => 'Service charge',
        'amount' => 10000,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 10000,
    ]);

    $service = app(DisputeInvoiceItemService::class);

    expect(fn () => $service->dispute($item, '   '))->toThrow(DomainException::class);

    $service->dispute($item->refresh(), 'Cleaning was not delivered in February');

    expect($item->refresh()->disputed_reason)->toBe('Cleaning was not delivered in February')
        ->and($item->disputed_by_id)->not->toBeNull()
        // …and the header is deliberately untouched, which is the distinction the whole fix rests on.
        ->and($invoice->refresh()->status)->not->toBe('disputed');
});

/*
|--------------------------------------------------------------------------
| …and once it is raised, the status is not a FIELD either
|--------------------------------------------------------------------------
|
| Reported from the panel on INV-VP-0002, a PAID invoice: the Status control still
| rendered as an open dropdown with a clear button, and `due_date`, `period_start` and
| `period_end` were still typeable. The pass above fixed which VALUES the picker offered
| without asking whether it should still be a control on a settled document — so an
| operator could take a paid invoice back to `issued`, blank a required field, or re-date
| the service period of a document the tenant has already paid and filed.
|
| The service period is the one that is not cosmetic, and `ChangeImpact` marking it NEUTRAL
| is exactly why it was missed: NEUTRAL is a statement about the LEDGER — it never reaches a
| journalizer payload — and these two columns move money through two other modules without
| ever touching a journal entry. `SyncCamPoolFromLedgerService` narrows the CAM pool's billed
| side on `invoices.period_start`, and `CreditUnearnedBillingService` both selects and
| apportions a move-out credit from the pair.
*/

/** Is this field disabled on the real mounted Edit page? */
function invoiceFieldIsDisabled(int $invoiceId, string $field): bool
{
    return Livewire::test(EditInvoice::class, ['record' => $invoiceId])
        ->instance()
        ->form
        ->getComponent($field)
        ->isDisabled();
}

it('does not offer the status as a control at all once the invoice is raised', function () {
    $issued = makeInvoice($this->lease);

    expect(invoiceFieldIsDisabled($issued->id, 'status'))->toBeTrue();
});

it('freezes the service period on a raised invoice, because CAM and move-out credits read it', function () {
    $issued = makeInvoice($this->lease);

    expect(invoiceFieldIsDisabled($issued->id, 'period_start'))->toBeTrue()
        ->and(invoiceFieldIsDisabled($issued->id, 'period_end'))->toBeTrue();
});

it('leaves a draft open where drafting happens, and moves issuing to the act', function () {
    // THE CONTROL, and the half that stops this becoming an over-lock: a draft's period, dates and
    // lines are still being settled, so those stay typeable. `status` is the one deliberate
    // EXCEPTION since SW-240 D-A — a saved draft's door is the **Issue** header act (confirmation,
    // `IssueInvoiceService::raise()`), one rule with the credit note, so the Select is a display
    // here too. The create form keeps the draft/issued choice: that is the born state, and
    // `ADraftInvoiceStaysADraftTest` drives it through the real create page.
    $draft = makeInvoice($this->lease, ['status' => 'draft']);

    foreach (['period_start', 'period_end', 'due_date'] as $field) {
        expect(invoiceFieldIsDisabled($draft->id, $field))->toBeFalse("draft {$field} was frozen");
    }

    expect(invoiceFieldIsDisabled($draft->id, 'status'))->toBeTrue()
        ->and(Livewire::test(EditInvoice::class, ['record' => $draft->id])->instance()->headerActs())
        ->toHaveKey('issue');
});

it('keeps the due date editable while the receivable is live, and closes it once money lands', function () {
    // Deliberately NOT locked with the rest: extending a due date as a one-off concession is an
    // ordinary AR act on an open charge — Yardi allows it, and this field's own helper text says
    // "override only for a one-off arrangement". It is meaningless on a settled invoice, where all
    // it can do is rewrite the ageing history the owner reads.
    $live = makeInvoice($this->lease);

    expect(invoiceFieldIsDisabled($live->id, 'due_date'))->toBeFalse();

    settleInvoiceInFull($live);

    expect(invoiceFieldIsDisabled($live->fresh()->id, 'due_date'))->toBeTrue();
});

it('freezes the due date on an invoice that left the books without ever being paid', function () {
    // The other half of `$settled`: a cancelled or written-off invoice has taken no money, so a
    // `paid_amount > 0` test alone would leave it editable. `InvoiceSettlement::RELIEVED` is the
    // register that already answers this, rather than a second list of statuses here.
    $writtenOff = makeInvoice($this->lease, ['status' => 'written_off']);

    expect(invoiceFieldIsDisabled($writtenOff->id, 'due_date'))->toBeTrue();
});
