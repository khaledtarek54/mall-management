<?php

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Models\User;
use App\Services\WriteOffInvoiceService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A write-off is an accounting act, not a status you type.
 *
 * `WriteOffInvoiceService` posts Dr Bad Debt / Cr AR against an `InvoiceWriteOff` row, records the
 * reason, refuses a closed period and caps the amount at what is outstanding — and its action is
 * gated on `invoices.void`. The status Select on the invoice form needs only `invoices.edit` and
 * offered `written_off` as a plain option, so a role deliberately denied the write-off could
 * produce its entire effect with none of it.
 *
 * What that cost, and why nothing surfaced it: a written-off invoice is skipped by the overdue
 * sweep, the late-fee sweep, the dunning ladder and both payment pickers, while `recomputeTotals()`
 * preserves the status as a manual override and leaves `balance` standing. So live AR left every
 * collection surface with no bad-debt entry behind it — and it was a ONE-WAY DOOR: "Write off"
 * hides once the status is set, and "Reverse write-off" hides while no `InvoiceWriteOff` row
 * exists, so neither way back was reachable from any screen.
 *
 * The same reasoning had already been written out in `InvoiceForm` for `cancelled` — "offering it
 * here let an operator cancel a paid invoice with none of that" — and was never generalised to its
 * sibling.
 *
 * Both layers are pinned, because each is worth nothing alone: the Select must not OFFER it (a
 * disabled or absent option is a UI truth), and the model must REFUSE it (the value still arrives
 * in a Livewire payload). Every refusal here is paired with a control that must succeed, or a guard
 * that refused everything would satisfy the refusals and read as a pass.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    // The Edit page is mounted below to read the real status field; without the catalogue its
    // canAccess() is false and Livewire::test()->instance() comes back null.
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));

    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->lease = makeLease($this->unit, null, ['status' => 'active']);
    $this->actor = User::factory()->create();
});

/** An issued invoice carrying a real outstanding balance. */
function collectableInvoice(): Invoice
{
    $invoice = makeInvoice(test()->lease);
    $invoice->update(['status' => 'issued']);

    expect((float) $invoice->fresh()->balance)->toBeGreaterThan(0.0);

    return $invoice->fresh();
}

it('refuses to mark an invoice written off through an ordinary save', function () {
    $invoice = collectableInvoice();

    expect(fn () => $invoice->update(['status' => 'written_off']))
        ->toThrow(DomainException::class);

    // The refusal is the whole point only if nothing moved: the debt is still collectable.
    $fresh = $invoice->fresh();
    expect($fresh->status)->not->toBe('written_off')
        ->and((float) $fresh->balance)->toBeGreaterThan(0.0)
        ->and(InvoiceWriteOff::where('invoice_id', $invoice->id)->exists())->toBeFalse();
});

it('still lets the write-off SERVICE do exactly what it always did', function () {
    $invoice = collectableInvoice();
    $outstanding = (float) $invoice->balance;

    // The control. The service saves the status with saveQuietly(), which fires no model events,
    // so the guard above must not be able to see it — and this is what proves the guard tells the
    // real path apart from the form rather than blocking both.
    $writeOff = app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    expect($writeOff)->toBeInstanceOf(InvoiceWriteOff::class)
        ->and((float) $writeOff->amount)->toEqual($outstanding)
        ->and($invoice->fresh()->status)->toBe('written_off');
});

it('does not offer written off, or any derived status, on the form', function () {
    $invoice = collectableInvoice();

    $options = statusOptionsFor($invoice);

    expect($options)->not->toHaveKey('written_off')
        ->and($options)->not->toHaveKey('cancelled')
        // Derived by recomputeTotals() from money — hand-setting one states something about cash
        // that no receipt backs.
        ->and($options)->not->toHaveKey('paid')
        ->and($options)->not->toHaveKey('partially_paid')
        ->and($options)->not->toHaveKey('overdue')
        // The control: the field is still usable for the transitions a person really does make.
        ->and($options)->toHaveKey('issued')
        ->and($options)->toHaveKey('disputed');
});

it('keeps the invoice own status in the list so an unrelated edit can still save', function () {
    $invoice = collectableInvoice();

    // Filament validates a Select by resolving the submitted value's label and refuses with
    // Rule::in([]) when it cannot. Dropping a status the record is ALREADY in would refuse every
    // save of that invoice, on a field the operator never touched — the shape that took `bank` out
    // of the deposit picker while both deposit forms defaulted to it.
    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    $writtenOff = $invoice->fresh();
    expect($writtenOff->status)->toBe('written_off')
        ->and(statusOptionsFor($writtenOff))->toHaveKey('written_off');
});

it('lets a genuinely written-off invoice be saved again without re-refusing', function () {
    $invoice = collectableInvoice();

    app(WriteOffInvoiceService::class)->write($invoice, ['reason' => 'tenant_insolvent']);

    // The row exists, so the guard's premise is satisfied and an ordinary save of an unrelated
    // field must go through. A guard keyed on the status alone would refuse this and make a
    // written-off invoice permanently uneditable.
    $writtenOff = $invoice->fresh();
    $writtenOff->update(['notes' => 'Referred to legal.']);

    expect($writtenOff->fresh()->notes)->toBe('Referred to legal.')
        ->and($writtenOff->fresh()->status)->toBe('written_off');
});

/**
 * The status field as the operator actually meets it — read off the MOUNTED Edit page.
 *
 * Not off a detached `InvoiceForm::configure(new Schema)`: a Filament component outside a mounted
 * Livewire container throws on `$container` the moment anything asks it to resolve a closure, which
 * is the same trap that makes `getHelperText()` unreadable in a sweep. Driving the real page is
 * also the stronger test — it is the screen the operator is standing on.
 */
function statusOptionsFor(Invoice $invoice): array
{
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($invoice->asset, isQuiet: true);

    $status = Livewire::test(EditInvoice::class, ['record' => $invoice->getKey()])
        ->instance()
        ->form
        ->getComponent('status');

    expect($status)->not->toBeNull('the invoice form no longer has a status field — this test is measuring nothing');

    return $status->getOptions();
}
