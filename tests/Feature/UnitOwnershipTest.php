<?php

use App\Enums\AssessmentBasis;
use App\Enums\ManagementFeeBasis;
use App\Enums\PartyType;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Models\Tenant;
use App\Models\UnitOwnership;
use App\Support\ValueSets;
use Spatie\Activitylog\Models\Activity;

/**
 * The ownership register — phase 1 of plan 08.
 *
 * What is pinned here is the shape the rest of the module stands on: a buyer is an AR party, a
 * resale is a tenure end rather than a delete, and the client's unanswered questions are rows whose
 * defaults are today's behaviour.
 */
beforeEach(function () {
    $this->asset = makeAsset();
    $this->unit = makeUnit($this->asset);
    $this->buyer = makeTenant(['party_type' => PartyType::UnitOwner->value]);
});

function makeOwnership(array $attrs = []): UnitOwnership
{
    return UnitOwnership::create(array_merge([
        'asset_id' => test()->asset->id,
        'unit_id' => test()->unit->id,
        'tenant_id' => test()->buyer->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ], $attrs));
}

it('records a buyer as an AR party rather than as a new kind of record', function () {
    $ownership = makeOwnership();

    // The whole design decision in one assertion: the owner IS a tenants row, so every money
    // surface downstream (payments, credit notes, ageing, the portal) already serves them.
    expect($ownership->owner)->toBeInstanceOf(Tenant::class)
        ->and($ownership->owner->isUnitOwner())->toBeTrue()
        ->and($ownership->owner->party_type)->toBe(PartyType::UnitOwner);

    // ...and a retailer is untouched by the column existing.
    expect(makeTenant()->party_type)->toBe(PartyType::Retailer);
});

it('defaults every unanswered client question to today behaviour', function () {
    $ownership = makeOwnership();

    // Plan 08 §8: the operator cannot answer these yet, so each is a row with a safe default rather
    // than a blocker. `area` in particular is what CAM already does, so a mixed building reconciles
    // exactly as it does now until somebody deliberately says otherwise.
    expect($ownership->tenure_type)->toBe(UnitTenureType::Freehold)
        ->and($ownership->assessment_basis)->toBe(AssessmentBasis::Area)
        ->and($ownership->management_mode)->toBe(UnitManagementMode::Vacant)
        ->and($ownership->fee_basis)->toBeNull();
});

it('casts the value-set columns to enums, which is what refuses an out-of-set value', function () {
    // The cast earns its place by giving the service a question instead of a string literal.
    $ownership = makeOwnership(['management_mode' => UnitManagementMode::OperatorManaged->value]);

    expect($ownership->management_mode->operatorCollectsRent())->toBeTrue();

    // For a CAST column the enum refuses at assignment — before the row is saved, so before the
    // ValueSets listener ever sees it. Pinned as ValueError rather than a vague Throwable, because
    // which layer refuses is the thing worth knowing when this changes.
    expect(fn () => makeOwnership(['status' => 'nonsense']))
        ->toThrow(ValueError::class);

    // Registration still earns its place: the registry is the shared vocabulary the importers read
    // and the no-DB-enums gate checks. Declared AS the enum class, so it resolves to the same cases
    // the model casts to and the two cannot drift into disagreeing about what is allowed.
    expect(ValueSets::allowed('unit_ownerships', 'status'))
        ->toBe(['reserved', 'contracted', 'handed_over', 'transferred'])
        ->and(ValueSets::allowed('unit_ownerships', 'fee_basis'))
        ->toBe(ManagementFeeBasis::values());
});

it('keeps the ValueSets guard working for a column that is registered but not cast', function () {
    // The other half of the story above, and the reason `guard()` now unwraps a BackedEnum: the two
    // mechanisms had never been combined before this module (the app's only enum-cast column was
    // not registered), and combining them threw "could not be converted to string" on every save.
    expect(fn () => makeUnit(test()->asset, ['status' => 'nonsense']))
        ->toThrow(DomainException::class);

    // Control — the refusal must be about the value, not about the fixture.
    expect(makeUnit(test()->asset, ['status' => 'vacant'])->exists)->toBeTrue();
});

it('bills only once the keys have changed hands', function () {
    // Handover is the trigger, not contract signature: the operator is still carrying the unit's
    // cost until the owner has it. A contracted-but-undelivered unit owes nothing.
    expect(makeOwnership(['status' => UnitOwnershipStatus::Contracted->value])->isBillableOn('2026-06-01'))->toBeFalse()
        ->and(makeOwnership(['status' => UnitOwnershipStatus::HandedOver->value])->isBillableOn('2026-06-01'))->toBeTrue();
});

it('treats a resale as a tenure end, never a deletion', function () {
    $seller = makeOwnership(['started_at' => '2026-01-01', 'ended_at' => '2026-06-30']);
    $buyer = makeOwnership([
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'started_at' => '2026-07-01',
    ]);

    // Both rows survive; exactly one is current on any given date. Deleting the seller would strand
    // every assessment invoice that quoted them.
    expect($this->unit->unitOwnerships()->count())->toBe(2)
        ->and($this->unit->ownershipOn('2026-03-01')->is($seller))->toBeTrue()
        ->and($this->unit->ownershipOn('2026-09-01')->is($buyer))->toBeTrue()
        ->and($seller->isBillableOn('2026-09-01'))->toBeFalse();
});

it('answers not-owned for an ordinary leased unit', function () {
    // The normal answer in a leased mall, and callers must read it as "this unit is let" rather
    // than as missing data.
    expect(makeUnit($this->asset)->isOwned())->toBeFalse();
});

it('refuses a tenure that ends before it starts', function () {
    expect(fn () => makeOwnership(['started_at' => '2026-06-01', 'ended_at' => '2026-01-01']))
        ->toThrow(DomainException::class);

    // Equal is allowed — a sale that collapses on its own handover date stays recordable — and the
    // control proves the refusal is about the ordering, not the fixture.
    expect(makeOwnership(['started_at' => '2026-06-01', 'ended_at' => '2026-06-01'])->exists)->toBeTrue();
});

it('reads its audit trail in Arabic as well as English, with no stored value leaking through', function () {
    // The activity log stores DATA and resolves every word at READ time — that is what lets ONE
    // stored row read correctly in both languages. It only works if the vocabulary points somewhere:
    // without a VALUE_VOCABULARY entry the diff renders the raw column value, so an Arabic reader
    // sees `handed_over` and `operator_managed` sitting in an otherwise Arabic sentence.
    $ownership = makeOwnership(['status' => UnitOwnershipStatus::Contracted->value]);

    $ownership->update([
        'status' => UnitOwnershipStatus::HandedOver->value,
        'management_mode' => UnitManagementMode::OperatorManaged->value,
        'tenure_type' => UnitTenureType::Usufruct->value,
    ]);

    $activity = Activity::query()->where('log_name', 'unit_ownership')->latest('id')->firstOrFail();

    foreach (['en' => 'Handed over', 'ar' => 'تم التسليم'] as $locale => $expected) {
        app()->setLocale($locale);
        $rendered = renderActivityChanges($activity);

        expect($rendered)->toContain($expected);

        // The stored values must NOT survive into either rendering — their presence is the symptom
        // of a missing vocabulary entry, and it looks identical to a working log in English.
        expect($rendered)->not->toContain('handed_over')
            ->and($rendered)->not->toContain('operator_managed')
            ->and($rendered)->not->toContain('usufruct')
            // ...and no untranslated key reached the screen either.
            ->and($rendered)->not->toContain('admin.');
    }

    // The Arabic rendering must not be the English one arriving via Lang's fallback — the trap
    // CLAUDE.md names, where a parity check passes because `Lang::has` falls back by default.
    app()->setLocale('ar');
    expect(renderActivityChanges($activity))->not->toContain('Handed over');
});

it('allocates a per-property reference series', function () {
    $first = makeOwnership();
    $second = makeOwnership(['unit_id' => makeUnit($this->asset)->id]);

    expect($first->reference)->toStartWith('UO-'.$this->asset->code.'-')
        ->and($first->reference)->toEndWith('0001')
        ->and($second->reference)->toEndWith('0002');
});
