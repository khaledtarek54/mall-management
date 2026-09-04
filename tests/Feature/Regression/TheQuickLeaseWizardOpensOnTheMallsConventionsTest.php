<?php

/*
|--------------------------------------------------------------------------
| The fast door opens on the same conventions as the slow one (SW-042)
|--------------------------------------------------------------------------
| Two doors create a lease: the full form and the leases list's quick-lease wizard. The form has
| read the configured conventions since EG-35 — the lease TERM from
| `AccountingSettings::default_lease_term_months`, the PAYMENT TERMS from
| `PropertySettings::paymentTermsDays()` (property → portfolio). The wizard prefilled the literals
| 36 and 7, so a mall that had configured either got neither, through the door people use when they
| are in a hurry.
|
| `LeaseCreationService` has its own `?? PropertySettings::paymentTermsDays()`, and it could never
| rescue this: the wizard's input is always dehydrated, so the payload always states a value and
| that branch is dead for this path. Testing the SERVICE would therefore have passed throughout.
|
| Both settings are moved OFF the old literals here (45 days portfolio, 24 months, 30 days at one
| mall) so no assertion can pass by coincidence, and the property tier is pinned separately from the
| portfolio one — a fix that reached only the portfolio would answer 45 and fail the first test.
*/

use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Settings\AccountingSettings;
use App\Settings\BillingSettings;
use App\Support\PropertySettings;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $billing = app(BillingSettings::class);
    $billing->default_payment_terms_days = 45;
    $billing->save();

    $accounting = app(AccountingSettings::class);
    $accounting->default_lease_term_months = 24;
    $accounting->save();

    $this->mall = makeAsset(['code' => 'QLW-A']);
    $this->otherMall = makeAsset(['code' => 'QLW-B']);

    // Mall A has negotiated 30-day terms; mall B inherits the portfolio's 45.
    PropertySettings::set('billing.default_payment_terms_days', $this->mall->id, 30);

    $this->actingAs(makeUser('super_admin', [$this->mall->id, $this->otherMall->id]));
});

it('opens the quick-lease wizard on the property’s own payment terms and the configured lease term', function () {
    asTenant($this->mall, function () {
        Livewire::test(ListLeases::class)
            ->mountAction(TestAction::make('quickLease')->table())
            // The ARRAY form. `assertSchemaStateSet(fn ($state) => ...)` ignores what the closure
            // returns, which is how a staleness bug survived being "tested" in August.
            ->assertActionDataSet([
                'lease.payment_terms_days' => 30,
                'lease.term_months' => 24,
            ]);
    });
});

it('falls back to the portfolio convention at a mall that has not overridden it', function () {
    // The control for the TIER, not just for the literal: a fix that read only the portfolio would
    // pass this and fail the test above, and one that read only the property tier the other way
    // round. Both are needed or "it is configured" is a claim about one number.
    asTenant($this->otherMall, function () {
        Livewire::test(ListLeases::class)
            ->mountAction(TestAction::make('quickLease')->table())
            ->assertActionDataSet([
                'lease.payment_terms_days' => 45,
                'lease.term_months' => 24,
            ]);
    });
});

it('gives the full lease form exactly the same two defaults', function () {
    // The point of the seam. If these two screens can drift again, the row is not closed — so the
    // slow door is asserted at the same figures, on the same mall, in the same run.
    asTenant($this->mall, function () {
        Livewire::test(CreateLease::class)
            ->assertOk()
            ->assertSchemaStateSet([
                'term_months' => 24,
                'payment_terms_days' => 30,
            ]);
    });
});
