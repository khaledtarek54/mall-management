<?php

/*
|--------------------------------------------------------------------------
| `leases.escalation_type` is a registered value set, not a translation array
|--------------------------------------------------------------------------
| The column stopped being a DB enum on 2026-08-10 (the migration that added `fixed_amount`) and
| the sweep that generated `App\Support\ValueSets` from the live schema ran on 2026-08-12 — two days
| later, by which time there was no enum left to see. So it spent its life as a bare `string(32)`
| with no runtime refusal, while the lease form fed its options from the `admin.enums` translation
| array: exactly the shape CLAUDE.md bans after the `Trade.category` episode.
|
| What that costs is specific and silent. `RentEscalationService` selects on the three escalating
| types, so a lease whose column says `annual_increase` — from an importer, a console command, or a
| crafted payload — is skipped by the sweep for ever, and its rent simply never steps. Nothing
| errors, no report is short, and the only symptom is money that was never billed.
|
| Two properties, both mutation-sensitive:
|   1. The guard REFUSES an out-of-set value — paired with a control that an in-set one saves, since
|      a refusal test passes just as happily against a model that rejects everything.
|   2. The registry and the label catalogue state the same set, in BOTH languages. That is what stops
|      them drifting apart again — and drift is not hypothetical here: the field help advertised a
|      "Step" type that existed in neither list.
*/

use App\Models\Lease;
use App\Support\ValueSets;
use Illuminate\Support\Facades\Lang;

it('refuses an escalation type the sweep would silently skip, and still accepts a real one', function () {
    $lease = makeLease(makeUnit(makeAsset()));

    // The control comes first: a set member must save, or the refusal below proves nothing.
    $lease->update(['escalation_type' => 'fixed_amount']);
    expect($lease->fresh()->escalation_type)->toBe('fixed_amount');

    // The failure this exists for. Before the registry entry this saved cleanly and the lease was
    // dropped from `RentEscalationService`'s `whereIn` for the rest of its life.
    expect(fn () => $lease->update(['escalation_type' => 'annual_increase']))
        ->toThrow(DomainException::class);

    expect($lease->fresh()->escalation_type)->toBe('fixed_amount');
});

it('states the same set in the registry and in both label catalogues', function () {
    $registry = ValueSets::allowed('leases', 'escalation_type');

    expect($registry)->toEqualCanonicalizing(['none', 'fixed_percent', 'fixed_amount', 'cpi']);

    foreach (['en', 'ar'] as $locale) {
        // `fallback: false` — `Lang::has()` falls back to English by default, so the obvious
        // spelling of this check only ever catches a key missing from BOTH languages.
        foreach ($registry as $type) {
            expect(Lang::has("admin.enums.escalation_type.{$type}", $locale, false))->toBeTrue(
                "escalation_type '{$type}' is in the registry with no {$locale} label, so the picker "
                .'would offer the raw key.'
            );
        }

        $labels = Lang::get('admin.enums.escalation_type', [], $locale);

        // The other direction, which is the one that drifted: a label with no registry entry is an
        // option the form would offer and the model would refuse on save.
        expect(array_keys($labels))->toEqualCanonicalizing($registry,
            "The {$locale} label catalogue and the registry disagree about what an escalation type is.");
    }
});

it('never lets a lease keep escalation terms it cannot act on', function () {
    // Not a new rule — `Lease::creating` already clears the terms when the clause is `none`. Pinned
    // here because the registry entry is what now guarantees `none` is the only way to say "no
    // escalation", so this is the behaviour the set is protecting.
    $lease = makeLease(makeUnit(makeAsset()), null, [
        'escalation_type' => 'none',
        'escalation_rate' => 7,
    ]);

    expect((float) $lease->fresh()->escalation_rate)->toBe(0.0);
});
