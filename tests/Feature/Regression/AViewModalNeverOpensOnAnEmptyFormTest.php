<?php

use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Schemas\Schema;
use Livewire\Livewire;

/**
 * **A record page's read-only View is rendered from the resource's own form, so a form with nothing
 * in it is a modal with nothing in it.**
 *
 * Filament resolves a resource ViewAction's schema as `infolist(form($schema))`
 * (`ListRecords::getDefaultActionSchemaResolver`), and the base `Resource::infolist()` returns the
 * schema untouched. `AccountingPeriodResource` declared `form()` and returned `$schema` — so the
 * View button on the period register opened a heading, a Close button, and nothing between them.
 *
 * **Why nothing caught it.** `ViewActionCoverageTest` skips a resource only when `form()` is not
 * DECLARED. A declared-but-empty one counts as having a form, so the gate then REQUIRED the very
 * action whose modal it could not fill — a gate checking a weaker property than its own name, the
 * shape this repo keeps recording. This file closes it from the other side: no resource may declare
 * a form with nothing in it, whether or not it offers a View.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->asset = makeAsset();
    // super_admin: this asks "does this schema hold anything", never "who may open it".
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Every admin resource, from the panel's own registry rather than a list — resource #67 is covered
 * the day it is written.
 *
 * Admin only, and stated rather than implied: the portal's resources are read-only lists whose
 * schemas are already mounted for real by `ResourceFormSmokeTest`, and building one here without a
 * `TenantUser` in the guard would prove nothing about them.
 *
 * @return array<int, class-string>
 */
function adminResourcesDeclaringForms(): array
{
    return collect(Filament::getPanel('admin')->getResources())
        // Only a resource that DECLARES its own form has a schema a view modal can render. The
        // base `Resource::form()` returns the schema untouched, which is what the three registers
        // with no form of their own rely on. `method_exists()` is useless here — form() is always
        // present, inherited.
        ->filter(fn (string $resource) => (new ReflectionMethod($resource, 'form'))
            ->getDeclaringClass()->getName() === $resource)
        ->values()
        ->all();
}

/** Recurses into groups: Filament wraps row actions in one once a table has more than a couple. */
function viewActionWithin(array $actions): bool
{
    foreach ($actions as $action) {
        if ($action instanceof ViewAction) {
            return true;
        }

        if ($action instanceof ActionGroup && viewActionWithin($action->getActions())) {
            return true;
        }
    }

    return false;
}

it('never lets a resource declare a form with nothing in it', function () {
    $empty = [];
    $checked = 0;

    foreach (adminResourcesDeclaringForms() as $resource) {
        $checked++;

        // `withHidden: true` so nothing evaluates `isHidden()` — that needs a mounted container and
        // would throw for reasons unrelated to this question. A schema that throws while BUILDING
        // is a different gate's defect (`ResourceFormSmokeTest` mounts every Create page for real),
        // so it is counted as non-empty here rather than reported as this one.
        $components = rescue(
            fn () => $resource::form(Schema::make())->getComponents(withActions: true, withHidden: true),
            ['unbuildable — not this test\'s question'],
            report: false,
        );

        if ($components === []) {
            $empty[] = class_basename($resource);
        }
    }

    expect($empty)->toBe([], 'These resources declare a form with no components, so their read-only View modal opens empty: '.implode(', ', $empty));

    // Vacuity guard: a sweep that silently matched nothing would pass for ever.
    expect($checked)->toBeGreaterThan(50);
});

it('offers no read-only view on the period register, because there is nothing to show', function () {
    asTenant($this->asset, function () {
        $table = Livewire::test(ListAccountingPeriods::class)->instance()->getTable();

        expect(viewActionWithin($table->getRecordActions()))->toBeFalse();

        // CONTROL. A refusal test that passes because the strip is empty proves nothing — the two
        // acts this screen exists for must still be on the row.
        $names = collect($table->getRecordActions())->map(fn ($a) => $a->getName())->all();

        expect($names)->toContain('close_period')
            ->and($names)->toContain('reopen_period');
    });
});
