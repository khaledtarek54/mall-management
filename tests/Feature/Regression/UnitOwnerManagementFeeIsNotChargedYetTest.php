<?php

use App\Enums\ManagementFeeBasis;
use App\Enums\PartyType;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\UnitOwnership;
use App\Services\Accounting\FiscalCalendar;
use App\Services\BillUnitOwnershipsService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The operator's management fee on an operator-managed unit is CONFIGURED BUT NOT CHARGED.
 *
 * `management_fee_pct` and `fee_basis` are written by the ownership form, cast on the model, gated
 * by `ValueSets` and logged by `ActivityVocabulary` — and read by no service anywhere. Nothing
 * computes the fee, bills it, posts it or reports it.
 *
 * That is a DEFERRAL, not an oversight: module 37 §8 blocks it on two accounting answers that only
 * the accountant can give — which GL account takes management-fee income (it is Eltizam's revenue,
 * not the property's, so a guess puts the operator's income in the owner's P&L), and which liability
 * account holds a sinking fund. Until those exist the fee cannot post.
 *
 * What was wrong was the SCREEN, not the schedule. The field's helper read "Our cut of what we
 * collect for this owner" in the present tense, and the `fee_basis` hint reasons about "charging a
 * fee on rent that was billed but never paid" — sentences that only parse if a fee is charged. An
 * operator set 5%, was told it was their cut, and no fee was ever raised. The helper now says so.
 *
 * This test characterises the CURRENT behaviour deliberately. When the fee is built it will fail,
 * which is the point: whoever builds it must come back here, and to the helper text.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'MF']);
    $this->unit = makeUnit($this->asset);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->unit->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
        'payment_terms_days' => 10,
        // The state the finding is about: we manage the unit and take a cut of what we collect.
        'management_mode' => UnitManagementMode::OperatorManaged->value,
        'management_fee_pct' => 5,
        'fee_basis' => ManagementFeeBasis::Collected->value,
    ]);

    Charge::create([
        'unit_ownership_id' => $this->ownership->id,
        'name' => 'Service charge',
        'type' => 'service_charge',
        'amount' => 3000,
        'currency' => 'EGP',
        'frequency' => 'monthly',
        'vat_applicable' => true,
        'is_active' => true,
        'start_date' => '2026-01-01',
    ]);
});

it('raises the assessment without any management-fee line', function () {
    app(BillUnitOwnershipsService::class)->runForPeriod(CarbonImmutable::parse('2026-03-01'));

    $invoice = Invoice::query()
        ->where('unit_ownership_id', $this->ownership->id)
        ->with('items')
        ->firstOrFail();

    // The assessment itself is raised — the control, so this test cannot pass by billing nothing.
    expect($invoice->items)->toHaveCount(1)
        ->and((float) $invoice->items->first()->amount)->toBe(3000.0);

    // ...and no fee line rides along with it, despite the 5% on the ownership.
    expect($invoice->items->contains(fn ($i) => str_contains(strtolower((string) $i->description), 'fee')))
        ->toBeFalse();
});

it('states on the ownership form that the fee is recorded but not billed', function () {
    // The screen must not describe a charge that never happens. Both languages, because an operator
    // reading the Arabic UI is the one most likely to be setting this.
    foreach (['en', 'ar'] as $locale) {
        expect(trans('admin.unit_ownerships.help.management_fee_pct', [], $locale))
            ->toContain($locale === 'en' ? 'no fee is billed' : 'لا تُحتسب');
    }
});

it('keeps the percentage the operator typed, so nothing is lost when the fee is built', function () {
    // The deferral must not become a reason to drop the data — the negotiated rate is a contractual
    // term, like a lease's security_deposit, and phase 5 reads it rather than re-asking for it.
    expect((float) $this->ownership->fresh()->management_fee_pct)->toBe(5.0)
        ->and($this->ownership->fresh()->fee_basis)->toBe(ManagementFeeBasis::Collected);
});
