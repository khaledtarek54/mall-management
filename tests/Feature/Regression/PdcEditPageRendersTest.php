<?php

use App\Filament\Admin\Resources\PostDatedCheques\Pages\CreatePostDatedCheque;
use App\Filament\Admin\Resources\PostDatedCheques\Pages\EditPostDatedCheque;
use App\Models\PostDatedCheque;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * Regression (render): the PDC form's invoice picker plucked a non-existent `invoices.reference`
 * column, so opening the EDIT page of a cheque with a tenant threw a 500 (the options closure runs
 * the query when tenant_id is set). It never surfaced because the demo seeded no cheques, so no edit
 * page was ever rendered with a record. This renders the form WITH a record — the "render Filament
 * with rows" guard — so a broken options/label closure fails the suite instead of a live page.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'PDC-RENDER']);
    $this->actingAs(makeUser('manager', [$this->asset->id]));
});

it('renders the PDC edit page for a cheque linked to a tenant + invoice', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant());
    $invoice = makeInvoice($lease, ['subtotal' => 3000, 'vat_amount' => 0, 'total' => 3000, 'balance' => 3000, 'status' => 'issued']);
    $cheque = PostDatedCheque::create([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $this->asset->id, 'tenant_id' => $invoice->tenant_id, 'lease_id' => $lease->id,
        'invoice_id' => $invoice->id, 'cheque_number' => 'CHQ-R-1', 'amount' => 3000, 'currency' => 'EGP',
        'cheque_date' => '2026-08-01', 'received_date' => '2026-07-01', 'status' => 'held',
    ]);

    asTenant($this->asset, function () use ($cheque) {
        Livewire::test(EditPostDatedCheque::class, ['record' => $cheque->id])->assertSuccessful();
    });
});

it('renders the PDC create page', function () {
    asTenant($this->asset, function () {
        Livewire::test(CreatePostDatedCheque::class)->assertSuccessful();
    });
});
