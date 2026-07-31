<?php

/*
|--------------------------------------------------------------------------
| Portal search is tenant-scoped, and it exists at all
|--------------------------------------------------------------------------
| The portal had a live global search bar nobody had configured: whichever of its six resources
| happened to carry a $recordTitleAttribute was searchable and the other three were not, and one
| character triggered a scan of every one of them. It now runs the same provider as the admin panel.
|
| The scoping question is sharper here than on the admin side: a leak crosses TENANTS, not
| properties — one retailer seeing another retailer's invoice.
*/

use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Support\Search\AtriomGlobalSearchProvider;
use Filament\Facades\Filament;

beforeEach(function () {
    $this->property = makeAsset(['code' => 'AW']);

    $this->mine = makeTenant(['name' => 'My Shop']);
    $this->theirs = makeTenant(['name' => 'Their Shop']);

    $this->myInvoice = makeInvoice(makeLease(makeUnit($this->property, ['code' => 'A-1']), $this->mine));
    $this->theirInvoice = makeInvoice(makeLease(makeUnit($this->property, ['code' => 'A-2']), $this->theirs));

    auth()->guard('portal')->login(makeTenantUser($this->mine));
    Filament::setCurrentPanel('portal');
});

it('finds the signed-in tenant\'s own invoice', function () {
    // The control first: without it, the leak assertion below passes on a broken search.
    $results = InvoiceResource::getGlobalSearchResults($this->myInvoice->number);

    expect($results)->toHaveCount(1)
        ->and($results->first()->title)->toBe($this->myInvoice->number);
});

it('never surfaces another tenant\'s invoice', function () {
    expect(InvoiceResource::getGlobalSearchResults($this->theirInvoice->number))->toHaveCount(0);
});

it('applies the same query floor on the portal', function () {
    expect((new AtriomGlobalSearchProvider)->getResults('A')->getCategories())->toHaveCount(0);
});

it('folds punctuation for a tenant reading a number off their invoice', function () {
    $results = InvoiceResource::getGlobalSearchResults(str_replace('-', '', $this->myInvoice->number));

    expect($results)->toHaveCount(1);
});
