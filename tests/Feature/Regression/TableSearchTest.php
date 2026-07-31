<?php

/*
|--------------------------------------------------------------------------
| List search, driven through the real Livewire path
|--------------------------------------------------------------------------
| `TableDefaults` gives every table the fold-normalized blob search as an EXTRA searchable column,
| ORed alongside whatever columns the table marks searchable. That composition is the risky part,
| and it is not something to reason about from the Filament source and call done:
|
|   - If Filament did not wrap the whole search in its own nested `where(...)` group, then
|     `(property scope AND blob) OR tenant.name` would bind as SQL AND-before-OR and the OR branch
|     would escape the property scope entirely. A hand-built query proves nothing here, because the
|     wrapper is exactly what a hand-built query omits.
|   - Filament splits the search into words and applies ONE nested group per word, so the fold runs
|     per word, not per query.
|
| So these drive the actual ListRecords component with an actual search string.
*/

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Vendors\Pages\ListVendors;
use App\Models\Vendor;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    $this->propertyA = makeAsset(['name' => 'Atriom Walk', 'code' => 'AW']);
    $this->propertyB = makeAsset(['name' => 'Nile Plaza', 'code' => 'NP']);

    $this->unitA = makeUnit($this->propertyA, ['code' => 'A-102']);
    $this->unitB = makeUnit($this->propertyB, ['code' => 'B-201']);

    $this->tenantA = makeTenant(['name' => 'Zebra Trading']);
    $this->tenantB = makeTenant(['name' => 'Zebra Trading']);

    $this->invoiceA = makeInvoice(makeLease($this->unitA, $this->tenantA));
    $this->invoiceB = makeInvoice(makeLease($this->unitB, $this->tenantB));

    auth()->login(makeUser('super_admin'));
    Filament::setCurrentPanel('admin');
});

it('keeps a list search inside the active property', function () {
    // THE assertion this file exists for. Both invoices belong to a tenant named "Zebra Trading",
    // so a search that escaped the property scope returns two rows.
    asTenant($this->propertyA, function () {
        $rows = tableRows(Livewire::test(ListInvoices::class)->set('tableSearch', 'Zebra Trading'));

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->id)->toBe($this->invoiceA->id);
    });
});

it('finds an invoice by its own number through the list search', function () {
    // The control: proves the search box actually works, so the assertion above is not passing
    // because list search is broken outright.
    asTenant($this->propertyA, function () {
        $rows = tableRows(Livewire::test(ListInvoices::class)->set('tableSearch', $this->invoiceA->number));

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->id)->toBe($this->invoiceA->id);
    });
});

it('finds an invoice from the list by a number typed without punctuation', function () {
    // This is what the blob buys the LIST, not just the top bar: before it, the invoice list could
    // only be searched for the number exactly as stored.
    asTenant($this->propertyA, function () {
        $rows = tableRows(Livewire::test(ListInvoices::class)
            ->set('tableSearch', str_replace('-', '', $this->invoiceA->number)));

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->id)->toBe($this->invoiceA->id);
    });
});

it('folds Arabic in the list search the same way the top bar does', function () {
    $this->tenantA->update(['name' => 'شركة أحمد للتجارة']);

    asTenant($this->propertyA, function () {
        $rows = tableRows(Livewire::test(ListTenants::class)->set('tableSearch', 'شركه احمد'));

        $ids = $rows->pluck('id')->all();

        expect(in_array($this->tenantA->id, $ids, true))
            ->toBeTrue('the tenant list could not find «شركة أحمد للتجارة» typed as «شركه احمد»');
    });
});

it('narrows the list as more words are typed', function () {
    // Filament applies one nested group per word and ANDs them. If the blob closure re-split the
    // whole query instead of folding the single word it is handed, this would widen instead.
    $this->tenantA->update(['name' => 'Zebra Alexandria']);
    $other = makeTenant(['name' => 'Zebra Cairo']);

    asTenant($this->propertyA, function () use ($other) {
        $broad = tableRows(Livewire::test(ListTenants::class)->set('tableSearch', 'Zebra'))->pluck('id');
        $narrow = tableRows(Livewire::test(ListTenants::class)->set('tableSearch', 'Zebra Cairo'))->pluck('id');

        expect($broad)->toContain($this->tenantA->id)->toContain($other->id)
            ->and($narrow)->toContain($other->id)->not->toContain($this->tenantA->id);
    });
});

it('searches vendor fields the list could not reach before', function () {
    // Concrete instance of the gap this closes: VendorsTable marked only `name` searchable while
    // global search covered legal_name, tax_id, email and phone — so the search bar could find a
    // vendor by tax ID that the vendor LIST could not.
    $vendor = Vendor::create([
        'name' => 'Delta Facilities',
        'legal_name' => 'Delta Facilities Management LLC',
        'tax_id' => '512-887-330',
        'email' => 'ops@delta.test',
        'phone' => '+20 100 555 7788',
        'type' => 'contractor',
        'status' => 'active',
    ]);

    asTenant($this->propertyA, function () use ($vendor) {
        foreach (['512887330', 'Management LLC', 'ops@delta.test', '01005557788'] as $query) {
            $ids = tableRows(Livewire::test(ListVendors::class)->set('tableSearch', $query))->pluck('id')->all();

            expect(in_array($vendor->id, $ids, true))
                ->toBeTrue("the vendor list could not find Delta Facilities by «{$query}»");
        }
    });
});

it('treats a punctuation-only query as a real query that matches nothing', function () {
    // The dangerous failure would be the opposite of what it looks like. When a query folds away,
    // the blob closure contributes NO constraint — and if it were the only searchable thing on the
    // table, an unconstrained group would return the ENTIRE table in response to a keystroke the
    // operator did not mean. Here the table's own columns still run `LIKE '%---%'` and match
    // nothing, which is the honest answer.
    //
    // Two invoices in scope, so "everything" (2) and "nothing" (0) cannot be confused.
    $second = makeInvoice(makeLease(makeUnit($this->propertyA, ['code' => 'A-103']), makeTenant()));

    asTenant($this->propertyA, function () {
        $unsearched = tableRows(Livewire::test(ListInvoices::class));
        $punctuation = tableRows(Livewire::test(ListInvoices::class)->set('tableSearch', '---'));

        expect($unsearched)->toHaveCount(2)
            ->and($punctuation)->toHaveCount(0);
    });
});
