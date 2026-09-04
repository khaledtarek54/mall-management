<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Vendors\Pages\ListVendors;
use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\Vendor;
use App\Models\VendorContract;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A PORTFOLIO-WIDE VENDOR CONTRACT IS EVERY MALL'S TO CHASE (SW-084).
 *
 * `vendor_contracts.asset_id` is nullable and a NULL means the contract covers EVERY mall — the
 * form says so in as many words ("a null here is a PORTFOLIO-WIDE contract covering every mall"),
 * the contracts tab reads it that way, and the migration that introduced `holidays` cites
 * `vendor_contracts` as the shape it copied.
 *
 * Four of the five readers disagreed, all by the same mechanism: **`whereIn` never matches NULL.**
 * The chase filter on the vendor list, the *contract notice* card on the dashboard, the COI card
 * beside it (through `whereHas('contracts', …)`) and the navigation badge each narrowed with a bare
 * `whereIn('asset_id', $ids)` — the card doing so under a comment claiming the opposite. So an
 * operator-wide security or cleaning contract at its notice deadline appeared on no list, in no
 * count and on no badge, while `vendors:scan-contract-renewals` — which scopes not at all — went on
 * e-mailing about it every night. The only reader that had it right is the contracts tab, i.e. the
 * one screen where somebody would eventually have noticed.
 *
 * `VendorContract::scopeInProperties()` is the one definition all five now read.
 *
 * Measured on `mall_management_qa` (2026-09-04): 7 contracts, 0 with a null `asset_id` — so the
 * demo data cannot show this and every screen looked right, which is why it survived.
 */
beforeEach(function (): void {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->here = makeAsset(['code' => 'HERE']);
    $this->elsewhere = makeAsset(['code' => 'ELSE']);

    // Three suppliers, one contract each, all at their notice deadline: the deadline is DERIVED in
    // VendorContract::saving as end_date − notice_period_days, so an end date ten days out with
    // sixty days' notice is a decision that was already due seven weeks ago.
    $contract = function (Vendor $vendor, ?int $assetId): VendorContract {
        return VendorContract::create([
            'vendor_id' => $vendor->getKey(),
            'asset_id' => $assetId,
            'name' => 'Cleaning',
            'status' => 'active',
            'start_date' => now()->subYear()->toDateString(),
            'end_date' => now()->addDays(10)->toDateString(),
            'notice_period_days' => 60,
        ]);
    };

    $this->portfolioVendor = Vendor::factory()->create(['name' => 'Portfolio Security Co']);
    $this->hereVendor = Vendor::factory()->create(['name' => 'Here Cleaning Co']);
    $this->elsewhereVendor = Vendor::factory()->create(['name' => 'Elsewhere Lifts Co']);

    // The row this whole file is about: no property, i.e. every property.
    $contract($this->portfolioVendor, null);
    $contract($this->hereVendor, $this->here->getKey());
    $contract($this->elsewhereVendor, $this->elsewhere->getKey());

    $this->actingAs(makeUser('manager', [$this->here->getKey()]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->here);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('puts a portfolio-wide contract on the chase list of the mall being worked in', function (): void {
    Livewire::test(ListVendors::class)
        ->filterTable('contract_notice_due', true)
        // The row that was missing…
        ->assertCanSeeTableRecords([$this->portfolioVendor, $this->hereVendor])
        // …and the control, which is what stops "show everything" passing as a fix: another mall's
        // contract is still another mall's problem.
        ->assertCanNotSeeTableRecords([$this->elsewhereVendor]);
});

it('counts it on the dashboard card that links to that list', function (): void {
    // The card and the filter must agree, or the operator clicks a count of 2 and lands on a list
    // of 1. Read through the widget's own view data rather than its markup.
    $items = (new ReflectionMethod(ActionRequired::class, 'getViewData'))->invoke(new ActionRequired)['items'];
    $card = collect($items)->firstWhere('key', 'contract_notice');

    expect($card)->not->toBeNull('the contract notice card is missing entirely');
    expect($card['title'])->toContain('2');
});

it('counts it on the vendors navigation badge', function (): void {
    // The badge asks a different question (expiring within 30 days, not notice-due) through the
    // same scope. It used to narrow with `where('asset_id', $currentId)`, stricter still.
    expect(VendorResource::getNavigationBadge())->toBe('2');
});

it('shows in the other mall too, and that mall shows only its own beside it', function (): void {
    // The pair that keeps the assertions above honest: portfolio-wide really does mean EVERY mall,
    // and the fix must not have turned the scope into "show everything". Standing in the other mall
    // the portfolio contract is still there and this mall's is not.
    Filament::setTenant($this->elsewhere);

    Livewire::test(ListVendors::class)
        ->filterTable('contract_notice_due', true)
        ->assertCanSeeTableRecords([$this->portfolioVendor, $this->elsewhereVendor])
        ->assertCanNotSeeTableRecords([$this->hereVendor]);
});
