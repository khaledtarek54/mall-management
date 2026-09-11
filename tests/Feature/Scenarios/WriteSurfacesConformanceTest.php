<?php

use App\Models\Area;
use App\Models\Charge;
use App\Models\RentableItem;
use App\Models\User;
use App\Support\WriteSurfaces;

/**
 * Self-enforcing gate for {@see WriteSurfaces} — **a change that reaches one door must have looked
 * at the others.**
 *
 * This codebase's most-repeated defect is a change that reached one write surface: the deposit modal
 * that never got the bank field its six sibling doors got, the fifteenth document-number allocator
 * sitting in the file below the fourteenth, the credit-note half of a line-narrative change that was
 * inert because no writer could store a key. `CLAUDE.md` and `/safe-change` step 2 both already
 * carry the rule; `MoneyDocumentDoors` carries the corollary — **a sentence is not a gate.**
 *
 * The guard proper is `atriom:doors --check-diff`, which can only run against a diff. What a
 * STATE gate can prove is the three things that make the diff check trustworthy:
 *
 *   1. every door is attributable to a record — a sweep that quietly stops collecting reports a
 *      clean run over a set it never looked at, which is this project's signature failure;
 *   2. the two doors that ARE directly comparable agree, or the divergence is registered with a
 *      reason somebody can review;
 *   3. a registered divergence still describes something.
 *
 * **Why parity is checked between one pair and not across the panel.** The first cut compared every
 * form-bearing Filament file against every other for the same model: **435 findings.** Most sibling
 * doors are supposed to differ — measured, the operator's tenant-request form asks twenty fields the
 * tenant's portal form does not, and every one is a field a tenant must not be able to set. So
 * parity is only meaningful between doors of the same kind AND the same audience. See
 * {@see WriteSurfaces} for the three reasons in full.
 */
it('attributes every write surface to a record', function () {
    $unresolved = WriteSurfaces::unclassifiable();

    expect($unresolved)->toBe(
        [],
        "A door that cannot be attributed to a record is invisible to `atriom:doors`, so a change\n"
        ."touching it would be told it has no siblings. Give the relation manager a \$relationship\n"
        ."Filament can resolve, or fix the relation it names:\n  - ".implode("\n  - ", array_map(
            fn ($why, $path) => "{$path}: {$why}",
            $unresolved,
            array_keys($unresolved),
        )),
    );
});

it('is actually comparing something — the check cannot quietly go vacuous', function () {
    // **The tooth the first six mutations could not supply, and review found its absence.**
    // Restoring the historical `$manager + ['panel' => $panel]` bug left every relation manager
    // panel-less, so NO pair matched and `parityDisagreements()` returned `[]` — which is exactly
    // what a clean sweep returns. All seven tests passed under it. An empty result is only
    // meaningful if something was compared, so the pair COUNT is the property to assert.
    expect(WriteSurfaces::comparablePairs())->not->toBeEmpty(
        'No relation manager is being compared to a resource form at all, so the parity check '
        .'below is reporting on nothing. This is what the panel-attribution bug looked like.',
    );

    // ...and every registered exemption must correspond to a divergence the comparison can SEE
    // with the registry ignored — otherwise the registry is exempting something the check is
    // blind to. Holds trivially when the registry is empty, which is its intended state: the first
    // version demanded a non-empty result and went red the day the one registered divergence was
    // CLOSED, i.e. the day the tool did its job.
    expect(count(WriteSurfaces::parityDisagreements(applyRegistry: false)))
        ->toBeGreaterThanOrEqual(count(WriteSurfaces::PARITY_DIVERGES));

    // ...and BOTH sides of every pair must yield fields the comparison can read. With the registry
    // empty the two assertions above are satisfied by a comparison that stopped comparing —
    // `fieldsAskedInSource()` no longer matching `EntitySelect::make(` drops `supervisors` from
    // both sides of the one real pair and the diff is still empty. Review, 2026-09-10.
    foreach (WriteSurfaces::comparablePairs() as [$path, $formPath]) {
        $manager = WriteSurfaces::fieldsAskedIn($path);
        $form = WriteSurfaces::fieldsAskedIn($formPath);

        expect($manager)->not->toBeEmpty("{$path} yields no fields — the field reader is blind")
            ->and($form)->not->toBeEmpty("{$formPath} yields no fields — the field reader is blind")
            ->and(array_intersect($manager, $form))->not->toBeEmpty(
                "{$path} and {$formPath} share no field at all, which for a creating manager and its "
                .'own record\'s form means one side is being read wrong',
            );
    }
});

it('says what a form asks that the manager does not — the comparison itself, on synthetic input', function () {
    // The real pairs answer EMPTY, which is their intended state and is indistinguishable from a
    // comparison that returns `[]` unconditionally (or diffs its arguments the wrong way round).
    // So the pure comparison is proved here on input whose answer is not empty.
    expect(WriteSurfaces::fieldsMissingFrom(['code', 'name', 'supervisors', 'asset_id'], ['code', 'name'], 'asset_id'))
        ->toBe(['supervisors'])
        // The linking key is a derivation, never a gap...
        ->and(WriteSurfaces::fieldsMissingFrom(['code', 'asset_id'], ['code'], 'asset_id'))->toBe([])
        // ...unless the door has no owner key to derive it from.
        ->and(WriteSurfaces::fieldsMissingFrom(['code', 'asset_id'], ['code'], null))->toBe(['asset_id'])
        // A manager asking MORE than its form is not this gate's question (an ATTACH pivot is the
        // shape that legitimately does), so the direction is one-way.
        ->and(WriteSurfaces::fieldsMissingFrom(['code'], ['code', 'notes'], null))->toBe([]);
});

it('does not keep an exemption for a file that is no longer there', function () {
    // `NOT_A_RECORD` is the fail-loud escape hatch, so it needs the same staleness tooth the
    // divergence registry has — an entry naming a deleted file silently exempts nothing while
    // reading as a reviewed decision.
    foreach (array_keys(WriteSurfaces::NOT_A_RECORD) as $path) {
        expect(file_exists(base_path($path)))->toBeTrue("NOT_A_RECORD names a missing file: {$path}");
    }
});

it('knows about every panel, importer and exporter it claims to sweep', function () {
    $doors = WriteSurfaces::doors();
    $byKind = [];

    foreach ($doors as $door) {
        $byKind[$door['kind']] = ($byKind[$door['kind']] ?? 0) + 1;
    }

    // **The gate asserts its own premise.** Every count below is a floor, not the measured number,
    // so ordinary growth never turns this red — but a refactor that stops a whole KIND of door being
    // collected does, which is the failure the two blinded gates of 2026-08-26 had and could not
    // see. Without this the three checks above it would pass most convincingly when they had swept
    // nothing at all.
    // **Per PANEL as well as per kind.** Admin alone supplies 66 resource forms and 66 relation
    // managers, so portal and vendor could both vanish inside `panels()`' Throwable catch and every
    // kind-level floor would still be met — the comment that used to claim this was covered was
    // simply false. The vendor panel's own floor is ZERO and that is recorded rather than asserted
    // away: its entire write surface is four ACTS, which is why the `action` kind had to exist.
    $perPanel = WriteSurfaces::doorsPerPanel();

    expect(array_keys($perPanel))->toContain('admin', 'portal', 'vendor')
        ->and($perPanel['admin'])->toBeGreaterThan(100)
        ->and($perPanel['portal'])->toBeGreaterThan(3);

    expect($byKind[WriteSurfaces::ACTION] ?? 0)->toBeGreaterThan(0)
        ->and($byKind[WriteSurfaces::RESOURCE_FORM] ?? 0)->toBeGreaterThan(50)
        ->and($byKind[WriteSurfaces::RELATION_MANAGER] ?? 0)->toBeGreaterThan(50)
        ->and($byKind[WriteSurfaces::IMPORTER] ?? 0)->toBeGreaterThan(5)
        ->and($byKind[WriteSurfaces::EXPORTER] ?? 0)->toBeGreaterThan(5)
        ->and($byKind[WriteSurfaces::API_RESOURCE] ?? 0)->toBeGreaterThan(5)
        ->and($byKind[WriteSurfaces::OFF_PANEL_CREATOR] ?? 0)->toBeGreaterThan(5);
});

it('reads an attach relation manager as writing the pivot, not the related record', function () {
    $doors = WriteSurfaces::doors();
    $attaching = 'app/Filament/Admin/RelationManagers/AssetStaffRelationManager.php';

    expect($doors)->toHaveKey($attaching);

    // This is the distinction the whole idea turns on. `AssetStaffRelationManager` offers
    // `AttachAction` and no `CreateAction`, so the fields on its `form()` — `title`, `assigned_at`,
    // `notes` — are columns of `asset_user`, NOT of `User`. Read as a door onto `User` it looks
    // like it is missing `roles` and `password`; it is missing nothing, and a user attached to a
    // property is not a user being created there.
    //
    // Measured: of the three managers the first version of this idea flagged, two were exactly
    // this misreading — this one and `DepartmentMembersRelationManager` beside it, both reported
    // as wanting `roles` and `password` — and only one was a real divergence.
    expect($doors[$attaching]['writes'])->toBeNull()
        ->and($doors[$attaching]['model'])->toBe(User::class);

    // The CONTROL, or the assertion above is satisfied by a sweep that reads nothing as a door.
    $creating = 'app/Filament/Admin/RelationManagers/AssetAreasRelationManager.php';

    expect($doors[$creating]['writes'])->toBe(Area::class);
});

it('reads a manager that writes through its OWN action as a door, not a read-only tab', function () {
    $doors = WriteSurfaces::doors();

    // `public function form(` was the first probe and it called these read-only. Measured, 17 of
    // 42 "read-only tabs" collect fields: `ChargeScheduleRelationManager::addCharge` is *the* door
    // onto `Charge` from the lease page (seven of its columns) and `WorkOrderComments` creates its
    // record from a `CreateAction::make()->schema()` with no `form()` at all. Both were excluded
    // from parity for ever AND printed by `atriom:doors` as *"read-only tab"* — a false statement
    // about a screen that creates records.
    expect($doors['app/Filament/Admin/RelationManagers/ChargeScheduleRelationManager.php']['writes'])
        ->toBe(Charge::class)
        ->and($doors['app/Filament/Admin/RelationManagers/WorkOrderCommentsRelationManager.php']['writes'])
        ->not->toBeNull();

    // The CONTROLS, because widening the probe to "collects any field" was the opposite mistake and
    // produced eleven findings of pure noise: a date-range FILTER and an ASSIGNMENT action that
    // picks an existing record are not doors onto that record's own columns.
    expect($doors['app/Filament/Admin/RelationManagers/TenantPaymentsRelationManager.php']['writes'])
        ->toBeNull()
        ->and($doors['app/Filament/Admin/RelationManagers/UnitOwnershipRentableItemsRelationManager.php']['writes'])
        ->toBeNull();

    // The assignment-action control above went VACUOUS on 2026-09-11: that tab's modal moved into
    // `RentableItemHoldingActions`, so its own source collects no field at all and the clause
    // being controlled for is never reached. The factory's source is what now carries the picker
    // — `rentable_item_id`, the related model's OWN foreign key — so the clause is asked of that
    // text directly, which is the only way it is still asked of anything.
    $factory = (string) file_get_contents(app_path('Filament/Actions/RentableItemHoldingActions.php'));

    expect(WriteSurfaces::fieldsAskedInSource($factory))->toContain('rentable_item_id')
        ->and(WriteSurfaces::writesTheRelatedRecord($factory, RentableItem::class))->toBeFalse();
});

it('does not count a resource that has no form as a form door', function () {
    // Twelve resources declare no `form(` at all — six portal read-only screens, the contractor
    // work-order screen and four admin registers. Claiming them inflated the premise floor and,
    // worse, printed them in `--check-diff` under a banner reading *these doors WRITE a record*.
    $doors = WriteSurfaces::doors();

    expect($doors)->not->toHaveKey('app/Filament/Portal/Resources/Leases/LeaseResource.php');

    // The CONTROL: a resource that DOES declare its form inline is still a door, or the fix would
    // have been "drop the fallback", which loses real surfaces.
    $formDoors = array_filter($doors, fn (array $d): bool => $d['kind'] === WriteSurfaces::RESOURCE_FORM);

    expect($formDoors)->not->toBeEmpty();
});

it('keeps its fail-loud registry load-bearing rather than blanket-silencing', function () {
    // `NOT_A_RECORD` exempts two API resources that render something outside `app/Models`. The
    // hazard of any such registry is that the REPORTING path stops working and the exemption gets
    // the credit — so ask the same question with the registry ignored and require the two files
    // back. Without this, deleting the whole check reads exactly like a clean sweep.
    $raw = WriteSurfaces::unclassifiable(applyRegistry: false);

    foreach (array_keys(WriteSurfaces::NOT_A_RECORD) as $path) {
        expect($raw)->toHaveKey($path);
    }

    expect(WriteSurfaces::unclassifiable())->toBe([]);
});

it('does not let a creating relation manager quietly ask less than its record’s own form', function () {
    $found = WriteSurfaces::parityDisagreements();

    expect($found)->toBe(
        [],
        "A relation manager that CREATES a record and its resource's own form are the same kind of\n"
        ."door for the same audience, so a field on one and not the other is either a defect or a\n"
        ."decision. Add the field, or register it in WriteSurfaces::PARITY_DIVERGES with why:\n  - "
        .implode("\n  - ", $found),
    );
});

it('does not keep a registered divergence that no longer describes anything', function () {
    $stale = WriteSurfaces::staleDivergences();

    // A stale exemption is the other half of the gate: it reads as a reviewed decision while the
    // code underneath has moved on. Same failure direction as a `blocked_by` naming a relation that
    // does not exist — the guard looks present and protects nothing.
    expect($stale)->toBe([], implode("\n  - ", $stale));

    // And the registry must be REACHABLE — an entry keyed to a path the sweep does not collect
    // would satisfy the parity check by exempting a door nobody is looking at.
    foreach (array_keys(WriteSurfaces::PARITY_DIVERGES) as $key) {
        [$path] = explode('::', $key, 2);

        expect(WriteSurfaces::doors())->toHaveKey($path);
    }
});

it('finds the siblings of a record from any one of its doors', function () {
    // The property the diff check rests on, asserted directly: from a form, the importer and the
    // API resource for one record must all be reachable. A `siblingsOf()` that answered nothing
    // would make `atriom:doors --check-diff` silently green on every change.
    $siblings = array_map(
        WriteSurfaces::pathOf(...),
        array_keys(WriteSurfaces::siblingsOf('app/Filament/Admin/Resources/Units/Schemas/UnitForm.php')),
    );

    expect($siblings)->toContain('app/Filament/Imports/UnitImporter.php')
        ->and($siblings)->toContain('app/Filament/Exports/UnitExporter.php')
        // ...and never itself, or every change would report the file it just edited.
        ->and($siblings)->not->toContain('app/Filament/Admin/Resources/Units/Schemas/UnitForm.php');
});

it('treats a model file as a door onto its own record', function () {
    // Changing `$fillable`, a cast or a `saving` hook is precisely the change that has to reach the
    // forms, the importer and the API — and a model is not a screen, so it is not in `doors()` and
    // had to be resolved on its own. Without this the widest class of change finds no siblings.
    $siblings = array_map(WriteSurfaces::pathOf(...), array_keys(WriteSurfaces::siblingsOf('app/Models/Unit.php')));

    expect($siblings)->toContain('app/Filament/Imports/UnitImporter.php');
});
