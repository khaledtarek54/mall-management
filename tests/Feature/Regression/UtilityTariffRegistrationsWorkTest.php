<?php

use App\Models\UtilityMeter;
use App\Models\UtilityTariff;
use App\Models\UtilityTariffRate;
use App\Support\ActivityVocabulary;
use App\Support\OwnerVisibility;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\DB;

/**
 * The tariff catalogue's REGISTRATIONS behave, not merely exist.
 *
 * Six registries had to be joined when the catalogue shipped (2026-08-17), and the conformance
 * gates named all six — but a gate asks *"is this model classified?"*, never *"does the
 * classification do anything?"*. That distinction is a documented failure mode in this codebase: a
 * green gate is not proof of the invariant it names.
 *
 * Probing them afterwards found exactly one that was classified and inert: `utility_type` had a
 * field LABEL (which the gate demanded and got) but no `VALUE_VOCABULARY` entry, so the activity
 * log printed a raw `electric` next to a form that says "Electricity". This file is the difference
 * between the gate's question and the real one.
 */
beforeEach(function () {
    $this->asset = makeAsset();
    $this->tariff = UtilityTariff::factory()->create([
        'utility_type' => 'electric',
        'name_en' => 'EGPC Commercial',
        'name_ar' => 'كهرباء تجاري',
    ]);
    UtilityTariffRate::factory()->create([
        'utility_tariff_id' => $this->tariff->id,
        'rate_per_unit' => 1.45,
    ]);
});

it('stores the morph ALIAS on an activity row, not the class name', function () {
    // `Relation::enforceMorphMap()` is on, so an unmapped model THROWS rather than storing an FQCN —
    // but that throw happens on write, in a nightly job nobody is watching. Asserting the stored
    // value keeps the alias itself pinned: renaming the class must not silently re-key history.
    $this->tariff->update(['provider' => 'North Cairo Electricity']);

    $row = DB::table('activity_log')->where('log_name', 'utility_tariff')->latest('id')->first();

    expect($row?->subject_type)->toBe('utility_tariff')
        ->and(Relation::getMorphedModel('utility_tariff'))->toBe(UtilityTariff::class)
        ->and(Relation::getMorphedModel('utility_tariff_rate'))->toBe(UtilityTariffRate::class);
});

it('refuses to delete a tariff any meter is priced by', function () {
    // `#[DeletableWhenUnused]` is the classification; `RefusesDeletionWhenReferenced` is what
    // enforces it. The attribute alone reads identically and guards nothing — which is why the
    // deletion gate checks for the trait separately, and why this asserts the refusal happens.
    UtilityMeter::create([
        'asset_id' => $this->asset->id,
        'meter_number' => 'E-501',
        'type' => 'electric',
        'status' => 'active',
        'utility_tariff_id' => $this->tariff->id,
    ]);

    expect(fn () => $this->tariff->delete())->toThrow(DomainException::class);
    expect(UtilityTariff::whereKey($this->tariff->id)->exists())->toBeTrue();
});

it('still deletes a tariff nothing is priced by', function () {
    // The control. A guard that refused everything would satisfy the refusal above and quietly make
    // the catalogue un-maintainable — the operator could never remove a tariff entered by mistake.
    $unused = UtilityTariff::factory()->create();

    $unused->delete();

    expect(UtilityTariff::whereKey($unused->id)->exists())->toBeFalse();
});

it('names the tariff in an activity diff instead of printing an id', function () {
    // The point of the `FOREIGN_KEYS` entry. Without it a rate change reads "Tariff: 7 → 9", which
    // tells the reader nothing about which price list moved. Needs `label()` on the model, so this
    // asserts the pair works end to end.
    $vocab = app(ActivityVocabulary::class);

    expect($vocab->value('utility_tariff_rate', 'utility_tariff_rate', 'utility_tariff_id', $this->tariff->id))
        ->toBe('EGPC Commercial');
});

it('translates the utility TYPE in the log, not just its column label', function () {
    // The one the gates could not see. `admin.fields.utility_type` satisfied the field-label check
    // while the VALUE stayed raw — the log said `electric` and the form said "Electricity" about
    // the same row.
    $vocab = app(ActivityVocabulary::class);

    expect($vocab->value('utility_tariff', 'utility_tariff', 'utility_type', 'electric'))
        ->toBe('Electricity');
});

it('labels both new columns in English AND Arabic', function () {
    // `Lang::has()` falls back to English, so a parity check written the obvious way passes on a
    // key that exists in neither. Asserting the Arabic STRING is what makes this real.
    $vocab = app(ActivityVocabulary::class);

    expect($vocab->field('utility_tariff', 'utility_type'))->toBe('Utility')
        ->and($vocab->field('utility_tariff_rate', 'utility_tariff_id'))->toBe('Tariff');

    app()->setLocale('ar');

    try {
        expect($vocab->field('utility_tariff', 'utility_type'))->toBe('المرفق')
            ->and($vocab->field('utility_tariff_rate', 'utility_tariff_id'))->toBe('التعريفة');
    } finally {
        app()->setLocale('en');
    }
});

it('shows an owner the price behind a recharge on their own property', function () {
    // An owner reads a utility recharge on their statement; the published price is the number that
    // explains the figure. Classified VISIBLE rather than operator-internal for that reason.
    expect(array_keys(OwnerVisibility::VISIBLE))->toContain('utility_tariffs');
});
