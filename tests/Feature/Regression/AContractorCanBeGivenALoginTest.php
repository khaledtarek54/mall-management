<?php

use App\Filament\Admin\Resources\Vendors\RelationManagers\ContactsRelationManager;
use App\Filament\Admin\Resources\Vendors\Pages\EditVendor;
use App\Models\Vendor;
use App\Models\VendorContact;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * A contractor can be given a login from a SCREEN.
 *
 * The `/vendor` panel shipped on 2026-08-28 with four verbs, its own reset-token table, a scope
 * that matches nothing when nobody is signed in, and no way for anyone to ever sign in.
 * `VendorContact::canAccessPanel()` requires `is_portal_user`; the column defaults false for every
 * row; and **nothing wrote it** — no form field, no importer, no seeder, no console command. A grep
 * found the model, the migration and two readers, and no writer at all.
 *
 * Filament's own bootstrap door is shut for the same reason: its password-reset page refuses to
 * send a link to somebody who `! canAccessPanel()`. So the panel was unenterable without editing
 * MySQL by hand, and every feature behind it — accept, evidence, update, quote, the dispatch bell —
 * was dead on arrival.
 *
 * This is the shape `ServiceReachability` gates one layer up (a service nothing can start) and that
 * `BillableAgreementIsConfigurableConformanceTest` gates one layer across (an agreement whose data
 * no screen can enter). An AUTH SURFACE has the same failure mode and had no gate, which is why the
 * assertion here is behavioural — drive the real relation manager and then sign in.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();

    $this->vendor = Vendor::create(['name' => 'Cool Air Co', 'status' => Vendor::STATUS_ACTIVE]);
    $this->contact = VendorContact::create([
        'vendor_id' => $this->vendor->id,
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
    ]);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset, isQuiet: true);
});

it('cannot reach the contractor panel until somebody grants it', function () {
    // The control for the test below: this is the state every contact starts in, and it must stay
    // refused — granting portal access is a decision, not a default.
    // Read back from the row, not from the in-memory instance: the column's default applies on
    // INSERT, so the attribute the create() call never set is still null on the object in hand.
    expect($this->contact->fresh()->is_portal_user)->toBeFalse()
        ->and($this->contact->canAccessPanel(Filament::getPanel('vendor')))->toBeFalse();
});

it('grants portal access from the vendor contacts screen', function () {
    Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $this->vendor,
        'pageClass' => EditVendor::class,
    ])->callTableAction('edit', $this->contact, data: [
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
        'is_portal_user' => true,
    ]);

    $granted = $this->contact->fresh();

    expect($granted->is_portal_user)->toBeTrue()
        // The property that actually matters: the panel now opens for them.
        ->and($granted->canAccessPanel(Filament::getPanel('vendor')))->toBeTrue();
});

it('withdraws access the same way, when somebody leaves the contractor', function () {
    $this->contact->update(['is_portal_user' => true]);

    Livewire::test(ContactsRelationManager::class, [
        'ownerRecord' => $this->vendor,
        'pageClass' => EditVendor::class,
    ])->callTableAction('edit', $this->contact, data: [
        'name' => 'Hani',
        'email' => 'hani@coolair.test',
        'is_portal_user' => false,
    ]);

    // Access, not identity — the contact row stays, so their history on past jobs is intact.
    expect($this->contact->fresh()->is_portal_user)->toBeFalse()
        ->and($this->contact->fresh()->canAccessPanel(Filament::getPanel('vendor')))->toBeFalse()
        ->and(VendorContact::whereKey($this->contact->id)->exists())->toBeTrue();
});
