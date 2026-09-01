<?php

use App\Filament\Admin\Pages\IncomeStatement;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Schema;
use Livewire\Livewire;

/**
 * Clearing a report filter must not 500 the page.
 *
 * Filament renders a blank option on every `Select` unless it is told otherwise. Clearing one sets
 * the bound Livewire property to `null` — and assigning null to a **non-nullable typed property**
 * UNSETS it, so every later read throws `PropertyNotFoundException` and the operator gets a 500 on
 * a page they were only filtering. Nothing is saved, nothing is logged, and the only way back is a
 * reload.
 *
 * Seven report screens had it — the fiscal-year picker on every financial statement (through the
 * shared bar), the billing-run preview, the ageing bucket, month-end close, the reports index, tax
 * depreciation and the revenue forecast. It is the sort of defect that survives review because the
 * control and the property are declared far apart and each is correct on its own.
 *
 * **The fix is the CONTROL, not the type.** There is no such thing as "no fiscal year" for a
 * statement, so offering the blank was offering an action that cannot work — and widening the
 * property to `?int` instead would have made every reader carry a fallback for a state the operator
 * should never have been able to ask for. Where a blank IS an answer it stays: `period` on the
 * shared ledger bar means "full year", says so in its placeholder, and is typed `?string`.
 *
 * This gate DRIVES each page rather than reading its source, because the control and the property
 * live in different files (often a trait) and no source sweep can pair them reliably.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Every `Select` anywhere inside a schema, however deeply nested.
 *
 * **`getChildSchemas()`, not `getComponents()`.** A filter bar is a `Section`, and a Section's
 * fields hang off CHILD SCHEMAS rather than off the component itself — so a top-level read returns
 * the Section and none of the Selects inside it. The first version of this sweep did exactly that,
 * found zero pages, and reported a clean bill of health; only the premise assertion below caught it.
 * That is this project's most repeated gate defect, hit again here while writing a gate against a
 * different one.
 *
 * @return array<int, string>
 */
function selectNamesIn(object $node): array
{
    $names = [];

    $children = method_exists($node, 'getComponents')
        ? $node->getComponents(withHidden: true)
        : [];

    foreach ($children as $component) {
        if ($component instanceof Select) {
            $names[] = $component->getName();
        }

        if (method_exists($component, 'getChildSchemas')) {
            try {
                foreach ($component->getChildSchemas() as $child) {
                    $names = [...$names, ...selectNamesIn($child)];
                }
            } catch (Throwable) {
                // A component that cannot be walked outside a mounted container — the `$container`
                // trap this project has hit repeatedly — is skipped rather than fatal.
            }
        }
    }

    return $names;
}

/**
 * Every admin Page that builds a filter form, paired with the bound properties its Selects write.
 *
 * Discovered from disk, so a NEW report page is covered by existing rather than by being added
 * here — the failure mode this whole file exists for.
 *
 * @return array<class-string, array<int, string>>
 */
function filterBoundProperties(): array
{
    $found = [];

    foreach (Filament::getPanel('admin')->getPages() as $page) {
        // `Page::class` through an IMPORT. Pest compiles a test file into its own namespace, so an
        // unimported `Filament\Pages\Page::class` resolves to
        // `P\Tests\Feature\Scenarios\Filament\Pages\Page` — a class that does not exist, which
        // `is_subclass_of()` answers FALSE for without complaining. Every one of the 37 pages was
        // silently skipped and this gate reported nothing to fix. Exactly the unimported-class trap
        // `UnresolvedClassReferenceConformanceTest` exists for, in the one directory it does not
        // sweep.
        if (! is_subclass_of($page, Page::class)) {
            continue;
        }

        // Only pages that actually render a filter form. `filtersForm`/`form` is where these live;
        // a page with neither has no control to clear.
        $method = collect(['filtersForm', 'form'])
            ->first(fn (string $m): bool => method_exists($page, $m));

        if ($method === null) {
            continue;
        }

        try {
            $component = Livewire::test($page);
        } catch (Throwable) {
            continue;   // gated off for this role, or needs route parameters — not this file's subject
        }

        try {
            $schema = $component->instance()->{$method}(Schema::make($component->instance()));
        } catch (Throwable) {
            continue;
        }

        // RECURSIVE. The filter bar is a Section, so a top-level read returns the container and
        // none of the Selects inside it — the first version of this sweep did exactly that, found
        // zero pages, and was caught only by the premise assertion below.
        $names = selectNamesIn($schema);

        if ($names !== []) {
            $found[$page] = $names;
        }
    }

    return $found;
}

it('survives the operator clearing any filter it offers', function () {
    $pages = filterBoundProperties();

    // The premise, asserted before anything is concluded: a sweep that found no pages would report
    // a clean bill of health having examined nothing.
    expect($pages)->not->toBeEmpty('no filter-bearing admin pages found — the sweep measured nothing');

    $broken = [];

    foreach ($pages as $page => $props) {
        foreach ($props as $prop) {
            $component = Livewire::test($page);

            if (! property_exists($component->instance(), $prop)) {
                continue;   // a schema-state field, not a bound Livewire property
            }

            try {
                // Exactly what Filament sends when the × is clicked.
                $component->set($prop, null)->assertOk();
            } catch (Throwable $e) {
                $broken[] = class_basename($page)."::\${$prop} — ".class_basename($e);
            }
        }
    }

    expect($broken)->toBe([],
        'Clearing these filters breaks their page. Either the blank is a real answer — then type the '
        .'property nullable and give its readers a fallback — or it is not, and the Select needs '
        .'->selectablePlaceholder(false) so the operator is never offered it:'."\n  "
        .implode("\n  ", $broken));
});

it('still lets the operator clear a filter whose blank MEANS something', function () {
    // The control. Without it this file would be satisfied by making every Select unclearable,
    // which would take away "full year" — a real answer an accountant needs.
    Livewire::test(IncomeStatement::class)
        ->set('period', null)
        ->assertOk()
        ->assertSet('period', null);
});
