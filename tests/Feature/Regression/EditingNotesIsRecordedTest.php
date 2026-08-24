<?php

use App\Models\Lease;
use App\Models\Tenant;
use App\Models\User;
use App\Support\ActivityLogging;
use App\Support\ActivityVocabulary;
use Database\Seeders\RolesPermissionsSeeder;
use Spatie\Activitylog\Models\Activity;

/**
 * Changing a field the audit trail did not happen to list recorded NOTHING.
 *
 * Reported from the panel: an operator edited the notes on a lease and the activity log stayed
 * empty — and not only on leases. The cause was structural rather than a bug in one model. Every
 * audited model named the columns it wanted logged (`->logOnly([...])`), so a column was invisible
 * to the trail until somebody remembered it, and `Lease` listed NINE of its fifty-two fillable
 * columns. Combined with `dontLogEmptyChanges()`, a save in which nothing WATCHED moved produced no
 * row at all — not a row with an empty diff — so the failure was silent by construction.
 *
 * Measured across the app when this was written: 84 audited models, 1,063 operator-settable
 * columns, 598 audited, **467 invisible (43%)**, and **33 models where editing `notes` recorded
 * nothing**. Yardi, MRI and Entrata all audit the entity and exclude noise; `App\Support\ActivityLogging`
 * inverts Atriom to match.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
});

it('records a notes-only edit on a lease — the symptom that was reported', function () {
    $lease = makeLease(makeUnit(makeAsset()));
    Activity::query()->delete();

    $lease->update(['notes' => 'Landlord agreed to defer the July service charge.']);

    $row = Activity::query()->where('log_name', 'lease')->latest('id')->first();

    expect($row)->not->toBeNull('Editing the notes on a lease still records nothing.');
    expect($row->attribute_changes['attributes'] ?? [])->toHaveKey('notes');
    expect($row->attribute_changes['attributes']['notes'])->toContain('defer the July service charge');
});

it('still records nothing when a save changes nothing', function () {
    // The control. Without it the test above passes just as happily against a trail that logs
    // every save, which would bury the human acts it exists to surface.
    //
    // `refresh()` first, and the reason is worth writing down: a freshly CREATED fixture holds
    // nulls in memory for columns the database defaulted, and `Lease::saving()` coerces those to
    // their defaults (the documented NOT-NULL coercion). The next save is therefore genuinely
    // dirty — five columns moving null → default — which the old nine-column allowlist could not
    // see and this one correctly can. That is the model's behaviour, not the trail's, and it does
    // not arise on a real edit because Filament loads the record from the database first.
    $lease = makeLease(makeUnit(makeAsset()));
    $lease->refresh();
    Activity::query()->delete();

    $lease->update(['notes' => $lease->notes]);

    expect(Activity::query()->where('log_name', 'lease')->count())->toBe(0);
});

it('audits substantially more of the lease than the nine columns it used to', function () {
    // The point is the DEFAULT, not one column: adding `notes` to an allowlist would have closed
    // the report and left the other forty-one fields silent.
    $logged = (new Lease)->attributesToBeLogged();

    expect(count($logged))->toBeGreaterThan(40)
        ->and($logged)->toContain('notes')
        // Operator classifications, which a naive `_type` suffix rule swallowed on the first draft.
        ->and($logged)->toContain('escalation_type')
        ->and($logged)->toContain('percentage_rent_calculation_type');
});

it('never audits a credential, and excludes only what earns it', function () {
    // `password` is FILLABLE on both User and Tenant, so flipping to logFillable() without the
    // denylist would write password hashes into activity_log on the very first save.
    //
    // Asserted against the POLICY, not against those two models. Both still carry their own
    // hand-tuned allowlists — they are flipped in a later tranche — so a model-level assertion
    // would pass because of the OLD code and go on passing with the credential entry deleted.
    // Measured: it did exactly that. Ask the thing this change actually controls.
    foreach ([User::class, Tenant::class] as $class) {
        // NB: toContain()'s extra arguments are more EXPECTED VALUES, not a message.
        expect((new $class)->getFillable())->toContain('password');
        expect(ActivityLogging::excludedFor(new $class))->toContain('password');
    }

    // And the exclusions that DO apply to a lease are the two that earn it — a derived blob and a
    // scheduled scan's stamp — not a swathe of real terms.
    expect(ActivityLogging::excludedFor(new Lease))
        ->toEqualCanonicalizing(['custom_fields', 'expiry_reminder_notified_at']);
});

it('labels every newly audited lease column in both languages', function () {
    // A column logged without a label humanises to an English word sitting in an Arabic cell.
    $vocabulary = app(ActivityVocabulary::class);
    $missing = [];

    foreach ((new Lease)->attributesToBeLogged() as $column) {
        foreach (['en', 'ar'] as $locale) {
            if (! $vocabulary->hasFieldLabel('lease', $column, $locale)) {
                $missing[] = "{$column} [{$locale}]";
            }
        }
    }

    expect($missing)->toBe([], 'Audited but unlabelled: '.implode(', ', $missing));
});
