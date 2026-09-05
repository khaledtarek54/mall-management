<?php

/*
|--------------------------------------------------------------------------
| "Credited" is the outcome of a credit note, not a status you pick
|--------------------------------------------------------------------------
| Found from the panel on 2026-09-01, on a fully-paid invoice: the operator
| opened Edit, moved Status from Paid → Issued → Credited, and it saved. The
| invoice then carried a captured receipt of 23,504.25, no credit note, and a
| status claiming a credit note had settled it.
|
| The form already states this exact rule twice — `cancelled` and `written_off`
| are removed from the options with a comment each explaining that they are the
| OUTCOME of an action, and the second of those comments records that the
| reasoning "was never generalised to it". This is the third time.
|
| **`credited` is worse than the statuses already removed, not milder.** The
| derived three (`paid`, `partially_paid`, `overdue`) are excluded because
| hand-setting one states something false about cash — but they self-correct on
| the next `recomputeTotals()`. `credited` is on that method's OWN exclusion
| list, so it sticks for ever. And it is load-bearing elsewhere:
|
|   - `InvoiceSettlement` classifies it RELIEVED, so nothing may settle it again
|   - `PaymentForm` denylists it, so it leaves the payment pickers
|   - `TenantStatementPdfService` omits it, so the TENANT'S OWN STATEMENT stops
|     showing an invoice they actually paid
|
| The books were never wrong — the journal entry stayed posted and correct, and
| `billing:reconcile` passed all eight checks throughout, because it reconciles
| the money and not the status. That is exactly why this needed catching here.
*/

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Models\Invoice;
use App\Support\InvoiceSettlement;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'CR']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $lease = makeLease(makeUnit($this->asset, ['code' => 'CR-01']), makeTenant(['name' => 'Paid In Full']));

    // Left ISSUED and unpaid on purpose: the form re-adds whatever the record
    // already IS, so a paid fixture would legitimately show 'paid' and the
    // options assertions would be testing the safety net rather than the rule.
    $this->invoice = makeInvoice($lease, ['status' => 'issued']);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('does not offer credited on the invoice form', function () {
    $options = Livewire::test(EditInvoice::class, ['record' => $this->invoice->getKey()])
        ->instance()->form->getComponent('status')->getOptions();

    expect($options)->not->toHaveKey('credited')
        // The other outcomes-of-an-action stay out too — this test is the guard
        // for the whole rule, not just the one that was reported.
        ->and($options)->not->toHaveKey('cancelled')
        ->and($options)->not->toHaveKey('written_off')
        // …and the derived three, which state something false about cash.
        ->and($options)->not->toHaveKey('paid')
        ->and($options)->not->toHaveKey('partially_paid')
        ->and($options)->not->toHaveKey('overdue')
        // `disputed` was this control's second half until SW-238 removed it too — the last
        // status with no act behind it (nothing in app/ ever wrote it, and the per-LINE dispute
        // act carries the reason). See APostedDocumentsStatusIsNotAPickerTest.
        ->and($options)->not->toHaveKey('disputed')
        // CONTROL: the option list is not simply empty. Without this, deleting every option
        // would satisfy every assertion above. (The FIELD is a display on a saved invoice since
        // SW-240 — the options still label the record's own state.)
        ->and($options)->toHaveKey('issued');
});

/*
| An invoice ALREADY in a removed state must keep it in the list, or Filament —
| which validates a Select by resolving the submitted value's label — refuses
| every save of that invoice, on a field the operator never touched. The form
| says so for the states it already removed; it has to hold for this one, and
| there are real rows in this state from before the fix.
*/
it('still lets a credited invoice be saved', function () {
    $this->invoice->forceFill(['status' => 'credited'])->saveQuietly();

    $options = Livewire::test(EditInvoice::class, ['record' => $this->invoice->getKey()])
        ->instance()->form->getComponent('status')->getOptions();

    expect($options)->toHaveKey('credited');
});

/*
| WHY it matters more than the statuses already blocked — asserted rather than
| described, so a future change to either cannot quietly make this milder.
*/
it('sticks, because recomputeTotals deliberately leaves it alone', function () {
    // Fully settled by a real receipt — the reported case exactly.
    settleInvoiceInFull($this->invoice);
    $this->invoice->refresh();
    expect($this->invoice->status)->toBe('paid');

    $this->invoice->forceFill(['status' => 'credited'])->saveQuietly();
    $this->invoice->recomputeTotals();

    // The derived statuses the form removes would have been corrected back to
    // 'paid' here. This one is not, which is why it must never be offered.
    expect($this->invoice->fresh()->status)->toBe('credited')
        ->and((float) $this->invoice->fresh()->paid_amount)->toBeGreaterThan(0.0);
});

it('takes the invoice off the books as far as settlement is concerned', function () {
    $credited = (clone $this->invoice)->forceFill(['status' => 'credited']);

    expect(InvoiceSettlement::accepts($credited))->toBeFalse()
        // CONTROL: an ordinary issued invoice still accepts settlement, so the
        // assertion above is about `credited` and not about accepts() answering
        // false for everything.
        ->and(InvoiceSettlement::accepts($this->invoice))->toBeTrue()
        ->and(InvoiceSettlement::relievedStatuses())->toContain('credited');
});
