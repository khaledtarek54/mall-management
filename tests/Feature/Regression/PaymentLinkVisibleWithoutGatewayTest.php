<?php

/**
 * The operator must be able to READ the bearer URL they are allowed to revoke.
 *
 * `/pay/{token}` is live from the moment an invoice exists: `Invoice::creating` mints the token,
 * and the mobile API publishes `payment_link_url` for any payable invoice — neither asks whether
 * Paymob is switched on. But the one screen that DISPLAYS the URL (the "Payment link" modal, with
 * the copy box and the QR) was gated on `paymob.enabled && isPayable()`, while the revoke action
 * beside it was gated only on the token existing.
 *
 * So on the shipped default (`PAYMOB_ENABLED=false`) the invoice screen offered "Regenerate
 * payment link" and nothing else: the operator could kill a credential they could not read, could
 * not answer a tenant asking "what does this link show?", and could not hand the link over at all.
 *
 * These tests are about the ASYMMETRY, so each one pairs the two actions — a test that only
 * asserted the modal shows would still pass if the revoke action vanished with it.
 */

use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant);
    $this->invoice = makeInvoice($this->lease, ['total' => 500, 'balance' => 500, 'status' => 'issued']);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('manager', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('offers the pay link beside the revoke action when the gateway is OFF', function () {
    // The shipped default, and the state the bug was reported from.
    config(['integrations.paymob.enabled' => false]);

    Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertActionVisible(TestAction::make('paymentLink')->table($this->invoice))
        ->assertActionVisible(TestAction::make('regeneratePaymentLink')->table($this->invoice));

    Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertActionVisible(TestAction::make('paymentLink'))
        ->assertActionVisible(TestAction::make('regeneratePaymentLink'));
});

it('still offers it once the invoice is settled — the URL keeps disclosing it', function () {
    // Same argument the revoke action already makes: /pay/{token}/status names the tenant and the
    // amount after settlement, so the operator's view of it has to outlive payability too.
    config(['integrations.paymob.enabled' => false]);
    settleInvoiceInFull($this->invoice);
    $this->invoice->refresh();

    expect($this->invoice->isPayable())->toBeFalse();

    Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertActionVisible(TestAction::make('paymentLink')->table($this->invoice))
        ->assertActionVisible(TestAction::make('regeneratePaymentLink')->table($this->invoice));
});

it('mounts the modal and renders the URL, the copy control and the state note', function () {
    // Two halves, because neither proves the other. Mounting is the seam Filament calls when the
    // operator opens the action — it runs `modalContent`'s closure, so a broken one fails here —
    // but the modal BODY renders in a lazy `action-modals` partial that never reaches the test
    // HTML, so the words have to be asserted against the view itself.
    config(['integrations.paymob.enabled' => false]);

    Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->mountAction(TestAction::make('paymentLink'))
        ->assertActionMounted(TestAction::make('paymentLink'));

    $html = view('filament.payment-link-modal', ['invoice' => $this->invoice])->render();

    expect($html)
        ->toContain($this->invoice->paymentLinkUrl())      // the whole point: the URL is readable
        ->toContain(__('admin.actions.copy'))
        // Gateway off: say so, rather than offering a "scan to pay" QR that cannot collect.
        ->toContain(__('admin.actions.payment_link_gateway_off'))
        ->not->toContain(__('admin.actions.scan_to_pay'));
});

it('shows the scan-to-pay QR only when the link can actually collect', function () {
    // The control for the test above: with the gateway on and a balance standing, the QR is the
    // point of the modal and the warning must not appear.
    config(['integrations.paymob.enabled' => true]);

    $html = view('filament.payment-link-modal', ['invoice' => $this->invoice])->render();

    expect($html)
        ->toContain(__('admin.actions.scan_to_pay'))
        ->toContain(__('admin.actions.payment_link_hint'))
        ->toContain('<svg')                                 // the QR really rendered
        ->not->toContain(__('admin.actions.payment_link_gateway_off'));
});

it('tells a settled invoice apart from a switched-off gateway', function () {
    // Both are "cannot collect", but the operator's next move differs — one is a config decision,
    // the other is nothing to chase — so the modal must not collapse them into one message.
    config(['integrations.paymob.enabled' => true]);
    settleInvoiceInFull($this->invoice);
    $this->invoice->refresh();

    $html = view('filament.payment-link-modal', ['invoice' => $this->invoice])->render();

    expect($html)
        ->toContain(__('admin.actions.payment_link_not_payable'))
        ->not->toContain(__('admin.actions.payment_link_gateway_off'))
        ->not->toContain(__('admin.actions.scan_to_pay'));
});

it('lets a read-only viewer READ the link but not revoke it', function () {
    // The two actions are permissioned differently on purpose: reading the URL is `invoices.view`,
    // killing a client's access to their own invoice is a write (`invoices.edit`). Loosening the
    // read side must not have loosened the write side with it — and a user with neither cannot
    // reach this screen at all, so `viewer` is where the split is actually observable.
    config(['integrations.paymob.enabled' => false]);

    $this->actingAs(makeUser('viewer', [$this->asset->id]));
    Filament::setTenant($this->asset);

    expect(auth()->user()->can('invoices.view'))->toBeTrue()
        ->and(auth()->user()->can('invoices.edit'))->toBeFalse();

    // Since 2026-08-30 the split spans two SURFACES, which makes it stricter rather than looser:
    // reading the URL is a read and stayed on the list, while revoking is a write and moved to the
    // invoice's own page — a page a viewer cannot open at all.
    Livewire::test(ListInvoices::class)
        ->assertTableActionVisible('paymentLink', $this->invoice);

    Livewire::test(EditInvoice::class, ['record' => $this->invoice->getRouteKey()])
        ->assertForbidden();
});
