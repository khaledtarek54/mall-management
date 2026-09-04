<?php

/*
|--------------------------------------------------------------------------
| A unit owner's payment terms follow the mall's convention (SW-056)
|--------------------------------------------------------------------------
| `UnitOwnership::paymentTermsDays()` carries a docblock stating the design in writing: the column
| is NOT NULL with a database default, so a `?? setting` fallback there could never fire, and "the
| property's convention belongs at ORIGINATION instead — the form pre-fills a new ownership from
| it, and from then on the sale carries its own number."
|
| The form did not. `UnitOwnershipForm.php:136` was `->default(7)`, a literal, while the lease form
| beside it has read `PropertySettings::paymentTermsDays(TenantScope::currentAssetId())` since
| EG-35. So a mall that had configured 30-day terms got 7 on every unit sale it recorded, and the
| docblock describing the mechanism was the only place the mechanism existed.
|
| ## What the row got wrong, and is deliberately NOT changed
|
| The row also names the MODEL's `$attributes` default of 7. That one stays. `$attributes` is a
| static array evaluated with no property in hand — it cannot read a per-property setting — and it
| exists to mirror the NOT NULL column default so an unsaved `new UnitOwnership` answers the same
| number the database would. Moving the convention there would put a second, conflicting answer
| beside the form's.
|
| The other origination door, `TransferUnitOwnershipService`, copies the SELLER's terms onto the
| buyer and must keep doing so: a resale inherits the arrangement that was agreed, not today's
| default. Only a brand-new ownership asks the mall.
|
| Both tiers are pinned, at figures moved off the old literal so nothing can pass by coincidence.
*/

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\UnitOwnerships\Pages\CreateUnitOwnership;
use App\Models\UnitOwnership;
use App\Settings\BillingSettings;
use App\Support\PropertySettings;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $billing = app(BillingSettings::class);
    $billing->default_payment_terms_days = 45;
    $billing->save();

    $this->mall = makeAsset(['code' => 'UOP-A']);
    $this->otherMall = makeAsset(['code' => 'UOP-B']);

    // Mall A has negotiated 30-day terms; mall B inherits the portfolio's 45. Neither is 7.
    PropertySettings::set('billing.default_payment_terms_days', $this->mall->id, 30);

    $this->actingAs(makeUser('super_admin', [$this->mall->id, $this->otherMall->id]));
});

it('opens a new unit sale on the mall’s own payment terms', function () {
    asTenant($this->mall, function () {
        Livewire::test(CreateUnitOwnership::class)
            ->assertOk()
            // The ARRAY form. `assertSchemaStateSet(fn ($state) => ...)` IGNORES what the closure
            // returns, which is how a staleness bug survived being "tested" in August 2026.
            ->assertSchemaStateSet(['payment_terms_days' => 30]);
    });
});

it('falls back to the portfolio convention at a mall that has not overridden it', function () {
    // The control for the TIER, not just for the literal: a fix that read only the portfolio setting
    // would pass this and fail the test above, and one that read only the property tier the other
    // way round. Both are needed, or "it is configured" is a claim about one number.
    asTenant($this->otherMall, function () {
        Livewire::test(CreateUnitOwnership::class)
            ->assertOk()
            ->assertSchemaStateSet(['payment_terms_days' => 45]);
    });
});

it('gives a sale and a lease at the same mall the same terms', function () {
    // The point of routing both through `PropertySettings::paymentTermsDays()`. Two agreements
    // billed by the same run, at the same mall, must not disagree about when their money is due —
    // and if these two screens can drift again the row is not closed.
    asTenant($this->mall, function () {
        Livewire::test(CreateLease::class)
            ->assertOk()
            ->assertSchemaStateSet(['payment_terms_days' => 30]);

        Livewire::test(CreateUnitOwnership::class)
            ->assertOk()
            ->assertSchemaStateSet(['payment_terms_days' => 30]);
    });
});

it('leaves the model’s own default alone — it mirrors the column, not the convention', function () {
    // The refused half of the row, pinned so it is not "fixed" later. An unsaved model must answer
    // what the database would; the convention is the FORM's job and only at origination.
    expect((new UnitOwnership)->payment_terms_days)->toBe(7);

    // And a saved ownership answers its own stored number, whatever the setting says today —
    // changing a mall's convention must never move the due date on assessments already raised.
    $ownership = UnitOwnership::create([
        'asset_id' => $this->mall->id,
        'unit_id' => makeUnit($this->mall)->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 14,
    ]);

    PropertySettings::set('billing.default_payment_terms_days', $this->mall->id, 60);

    expect($ownership->fresh()->paymentTermsDays())->toBe(14);
});
