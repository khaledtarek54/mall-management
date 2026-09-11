<?php

use App\Filament\Portal\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Portal\Resources\Invoices\Pages\ViewInvoice;
use App\Models\Invoice;
use App\Services\WriteOffInvoiceService;
use App\Support\DemoPayments;
use Carbon\CarbonImmutable;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * THE TENANT'S INVOICE LIST AND THE INVOICE'S OWN PAGE GIVE ONE ANSWER TO "MAY I PAY THIS?".
 *
 * *Pay now* and *Pay (demo)* were defined twice on the portal — `ViewInvoice`'s header and
 * `InvoicesTable`'s row — each with its own predicate. On 2026-09-01 the page's was routed to
 * `Invoice::isPayable()`; the table's `payDemo` was not (its `payNow` already was), and on
 * 2026-09-11 it still read `balance > 0` and a three-status denylist. Measured through the real
 * components: an invoice with 6,000 of 10,000 written off and the other 4,000 then paid offered
 * *Pay (demo)* on the LIST (raw balance 6,000 > 0, status `partially_paid`) and NOT on its own
 * page (`isPayable()` nets the write-off and asks `InvoiceSettlement`). Pressing it reached the
 * demo capture, which refused with a failure modal — so nothing was taken, and the tenant read a
 * button that did not work beside a page that said nothing was owed. Both modals quoted the raw
 * balance.
 *
 * `App\Filament\Portal\Actions\InvoiceActions` is the one definition both surfaces compose.
 */
beforeEach(function () {
    config(['integrations.paymob.enabled' => false, 'integrations.demo_payments.enabled' => true]);
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit(makeAsset()), $this->tenant, ['status' => 'active']);

    expect(DemoPayments::enabled())->toBeTrue();
});

afterEach(fn () => Filament::setCurrentPanel(Filament::getPanel('admin')));

/** Sign in as the tenant's admin user — AFTER any operator act, since a write-off stamps `created_by` from the guard. */
function asPortalTenant(): void
{
    Filament::setCurrentPanel(Filament::getPanel('portal'));
    test()->actingAs(makeTenantUser(test()->tenant), 'portal');
}

/** An operator writes part (or all) of the invoice off — the act only an operator may do. */
function writtenOffByOperator(Invoice $invoice, float $amount): Invoice
{
    test()->actingAs(makeUser('accounting'));

    app(WriteOffInvoiceService::class)->write($invoice->fresh(), [
        'amount' => $amount,
        'reason' => 'settled_short',
        'write_off_date' => CarbonImmutable::now()->toDateString(),
    ]);

    return $invoice->fresh();
}

function portalInvoice(array $attrs = []): Invoice
{
    return makeInvoice(test()->lease, array_merge([
        'status' => 'issued', 'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000,
        'paid_amount' => 0, 'balance' => 10000,
        'issue_date' => CarbonImmutable::now()->subMonth(),
        'due_date' => CarbonImmutable::now()->subWeek(),
    ], $attrs));
}

it('offers Pay on both surfaces for an ordinary open invoice — the control', function () {
    $invoice = portalInvoice();
    asPortalTenant();

    Livewire::test(ListInvoices::class)->assertTableActionVisible('payDemo', $invoice);
    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])->assertActionVisible('payDemo');
});

it('offers Pay on NEITHER surface once the remainder of a part-written-off invoice is paid', function () {
    // THE divergent state. A full write-off moves the status to `written_off`, which the old row
    // copy also refused — that case is not a tooth. Write off 6,000, and the tenant pays the
    // 4,000 still owed: status `partially_paid` (live), raw `balance` 6,000 (a write-off leaves it
    // standing by design), collectable 0. The old row offered *Pay* on the forgiven 6,000; the
    // page, on `isPayable()`, did not.
    $invoice = writtenOffByOperator(portalInvoice(), 6000);
    asPortalTenant();

    Livewire::test(ListInvoices::class)
        ->callTableAction('payDemo', $invoice)
        ->assertHasNoTableActionErrors();

    $invoice = $invoice->fresh();

    expect($invoice->status)->toBe('partially_paid')
        ->and(round((float) $invoice->balance, 2))->toEqual(6000.0)
        ->and($invoice->isPayable())->toBeFalse();

    // The list was the surface that got this wrong.
    Livewire::test(ListInvoices::class)
        ->assertCanSeeTableRecords([$invoice])
        ->assertTableActionHidden('payDemo', $invoice);

    Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])->assertActionHidden('payDemo');
});

it('asks the tenant to confirm the amount the capture will TAKE, net of a partial write-off', function () {
    $invoice = writtenOffByOperator(portalInvoice(), 6000);
    asPortalTenant();

    expect(round((float) $invoice->balance, 2))->toEqual(10000.0)
        ->and($invoice->payableAmount())->toEqual(4000.0);

    // Still payable — 4,000 is genuinely owed — and BOTH modals name 4,000, not the 10,000 the
    // write-off deliberately leaves standing on `balance`.
    $row = (string) Livewire::test(ListInvoices::class)->instance()->getTable()
        ->getAction('payDemo')->record($invoice)->getModalDescription();
    $page = (string) Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])->instance()
        ->getAction('payDemo')->getModalDescription();

    foreach ([$row, $page] as $description) {
        expect($description)->toContain('4,000.00')->not->toContain('10,000.00');
    }
});

it('is one definition — the page and the row render the same two acts', function () {
    $invoice = portalInvoice();
    asPortalTenant();

    $row = Livewire::test(ListInvoices::class)->instance()->getTable();
    $page = Livewire::test(ViewInvoice::class, ['record' => $invoice->getKey()])->instance();

    foreach (['payNow', 'payDemo'] as $name) {
        expect($row->getAction($name))->not->toBeNull()
            ->and($page->getAction($name))->not->toBeNull();
    }
});
