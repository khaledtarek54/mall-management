<?php

use App\Filament\Admin\Widgets\ActionRequired;
use App\Models\PurchaseRequest;
use App\Models\Vendor;
use App\Models\VendorBill;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Spatie\Permission\Models\Permission;

/**
 * Work waiting on a decision appears where the operator already looks (UX5-10).
 *
 * A purchase request at `requested` and a supplier bill at `draft` are both stopped until a person
 * decides, and each was visible only to whoever thought to open that register. `ActionRequired` is
 * already the one panel answering "what needs doing" — seventeen cards, each gated on the register
 * it links to — and carried nothing about approvals. Cards there rather than a new screen: a
 * second place to look is what this widget exists to prevent.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The card keys this role is shown for this property. */
function actionCardKeys(string $role, $asset): array
{
    test()->actingAs(makeUser($role, [$asset->id]));
    Filament::setTenant($asset);

    // Through the widget's real view data, which is what the page renders — including the
    // per-card permission filter, which is half the claim.
    return collect((new ActionRequired)->getViewData()['items'] ?? [])->pluck('key')->all();
}

it('lists a purchase request and a supplier bill that are waiting', function () {
    PurchaseRequest::create([
        'asset_id' => $this->asset->id,
        'reference' => 'PR-TEST-1',
        'status' => PurchaseRequest::STATUS_REQUESTED,
        'justification' => 'Replacement filters',
    ]);

    VendorBill::create([
        'asset_id' => $this->asset->id,
        'vendor_id' => Vendor::create(['name' => 'Acme', 'status' => 'active'])->id,
        'reference' => 'BILL-TEST-1',
        'bill_date' => now()->subDays(9)->toDateString(),
        'due_date' => now()->addDays(21)->toDateString(),
        'status' => 'draft',
        'category' => 'maintenance',
        'subtotal' => 1000, 'vat_amount' => 140, 'total' => 1140, 'balance' => 1140,
    ]);

    $keys = actionCardKeys('super_admin', $this->asset);

    expect($keys)->toContain('awaiting_purchase_approval')
        ->and($keys)->toContain('awaiting_bill_approval');
});

it('says nothing when the queues are empty', function () {
    // A panel that always shows a card is one people stop reading — the rule every other card in
    // this widget follows.
    $keys = actionCardKeys('super_admin', $this->asset);

    expect($keys)->not->toContain('awaiting_purchase_approval')
        ->and($keys)->not->toContain('awaiting_bill_approval');
});

it('shows neither card to a role that cannot open the register', function () {
    PurchaseRequest::create([
        'asset_id' => $this->asset->id,
        'reference' => 'PR-TEST-2',
        'status' => PurchaseRequest::STATUS_REQUESTED,
        'justification' => 'Filters',
    ]);

    // `leasing` holds neither purchase_requests.view nor vendor_bills.view — being told about work
    // and handed a link that 403s is the defect this widget's per-card gating exists for.
    $leasing = actionCardKeys('leasing', $this->asset);

    expect($leasing)->not->toContain('awaiting_purchase_approval');

    // THE CONTROL: a role that DOES hold it is told. Without this the refusal passes just as
    // happily on a card nobody ever sees.
    expect(actionCardKeys('super_admin', $this->asset))->toContain('awaiting_purchase_approval');
});

it('maps every card to a permission that actually exists', function () {
    // **The failure this file walked into.** `CARD_PERMISSIONS` was given
    // `purchase_requests.view`, which is not a permission — the resource's module is `procurement`
    // — so the card was invisible to everyone including a super admin, and nothing said so.
    //
    // The widget's own docblock covers the opposite case: an UNMAPPED key stays visible, "the
    // mapping is the gate; the omission is loud". A mapping to a name nobody grants is silent, and
    // silence on a panel whose whole job is telling you what needs doing is the worse direction.
    $this->seed(RolesPermissionsSeeder::class);

    $known = Permission::pluck('name')->all();

    // The premise first: this test is worthless if the catalogue did not seed.
    expect(count($known))->toBeGreaterThan(100);

    $mapped = (new ReflectionClass(ActionRequired::class))->getConstant('CARD_PERMISSIONS');

    $unknown = collect($mapped)->unique()->reject(fn (string $p) => in_array($p, $known, true))->values()->all();

    expect($unknown)->toBe([],
        'these cards are gated on permissions that do not exist, so nobody will ever see them: '.implode(', ', $unknown));
});
