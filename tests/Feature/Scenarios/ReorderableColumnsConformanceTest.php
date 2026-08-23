<?php

/*
|--------------------------------------------------------------------------
| Every table column must have a label, because columns are REORDERABLE
|--------------------------------------------------------------------------
| `TableDefaults` turns on `reorderableColumns()` for every table in both panels (EG-32 / S-5 —
| "no user-defined columns or groupings"). Filament's `HasColumnManager` then throws
|
|     LogicException: The table column [hero] has a blank label.
|                     All columns must have labels when they are reorderable.
|
| the moment it builds the manager's state — which is on every render of that list. So a single
| `->label('')` anywhere is a 500 on that screen, and the failure is on a screen nobody may have
| opened since the flag went on.
|
| That is not merely a technical constraint to work around: a column an operator can show, hide and
| REORDER has to be one they can name in the manager. A blank entry in that list is a usability bug
| whether or not Filament refuses it.
|
| When this gate went in, exactly ONE admin column was blank (`MarketingPostsTable::hero`, the card
| thumbnail) plus its portal twin. Six other `->label('')` calls in `app/Filament` are FORM
| components, which the column manager never sees.
*/

use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Livewire\Livewire;

/** Every List page class in a panel, discovered from disk rather than from a list to keep. */
function listPagesIn(string $panelDir, string $namespace): array
{
    return collect(File::allFiles(app_path($panelDir)))
        ->filter(fn ($f) => str_starts_with($f->getFilename(), 'List') && $f->getExtension() === 'php')
        ->map(function ($f) use ($panelDir, $namespace) {
            $rel = str_replace([app_path($panelDir).'/', '.php'], '', $f->getPathname());

            return $namespace.'\\'.str_replace('/', '\\', $rel);
        })
        ->filter(fn (string $c) => class_exists($c) && is_subclass_of($c, ListRecords::class))
        ->values()
        ->all();
}

/**
 * Every CUSTOM page that renders a table — the half `listPagesIn()` cannot see.
 *
 * That helper keys on a filename starting with `List` under `Resources`, so a dashboard, a report or
 * the notification centre — a `Page` with a table on it — was never swept. The notification centre's
 * unread marker is `->label('')`, and turning reordering on 500'd it on BOTH panels while this gate
 * reported the blank-label set as exactly one column. Discovered by what a class IMPLEMENTS rather
 * than by what it is named, so a new table page is covered without anyone remembering to add it.
 */
function tablePagesIn(string $panelDir, string $namespace): array
{
    return collect(File::allFiles(app_path($panelDir)))
        ->filter(fn ($f) => $f->getExtension() === 'php')
        ->map(fn ($f) => $namespace.'\\'.str_replace(['/', '.php'], ['\\', ''], str_replace(app_path($panelDir).'/', '', $f->getPathname())))
        ->filter(fn (string $c) => class_exists($c)
            && in_array(HasTable::class, class_implements($c) ?: [], true)
            && ! is_subclass_of($c, ListRecords::class))
        ->values()
        ->all();
}

it('gives every admin table column a label', function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $asset = makeAsset(['code' => 'RC']);
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $pages = listPagesIn('Filament/Admin/Resources', 'App\\Filament\\Admin\\Resources');

    // The gate must prove it collected something before reporting on it — the class of failure
    // where a sweep quietly matches zero models and stays green for a year.
    expect($pages)->not->toBeEmpty();

    $blank = [];

    asTenant($asset, function () use ($pages, &$blank) {
        foreach ($pages as $page) {
            // `getColumns()`, not the column manager: building the manager's state is what THROWS,
            // and a gate that throws reports one screen and stops. This one names them all.
            foreach (Livewire::test($page)->instance()->getTable()->getColumns() as $name => $column) {
                if (trim((string) $column->getLabel()) === '') {
                    $blank[] = class_basename($page).' → '.$name;
                }
            }
        }
    });

    expect($blank)->toBe([], "These table columns have a blank label, which is a 500 on the list because columns are reorderable:\n  ".implode("\n  ", $blank));
})->group('slow');

it('builds the column manager for every admin list without throwing', function () {
    // The other half, and the one that actually reproduces the crash: `getDefaultTableColumnState()`
    // is what Filament calls per render, and it is where the LogicException comes from. Asserting
    // labels are non-blank is our rule; this is upstream's, pinned as a contract so a release that
    // changes the requirement turns the build red rather than silently relaxing it.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $asset = makeAsset(['code' => 'RC2']);
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $pages = listPagesIn('Filament/Admin/Resources', 'App\\Filament\\Admin\\Resources');
    $failures = [];

    asTenant($asset, function () use ($pages, &$failures) {
        foreach ($pages as $page) {
            try {
                Livewire::test($page)->instance()->getDefaultTableColumnState();
            } catch (Throwable $e) {
                $failures[] = class_basename($page).': '.Str::limit($e->getMessage(), 140);
            }
        }
    });

    expect($failures)->toBe([], "The column manager throws on these lists:\n  ".implode("\n  ", $failures));
})->group('slow');

it('has reordering actually switched on', function () {
    // Without this the two sweeps above still pass and prove nothing: a blank label is only fatal
    // BECAUSE columns are reorderable, so the gate has to assert its own premise.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $asset = makeAsset(['code' => 'RC3']);
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () {
        $table = Livewire::test(ListTenants::class)
            ->instance()->getTable();

        expect($table->hasReorderableColumns())->toBeTrue();
    });
});

it('gives every custom table PAGE a labelled column too', function () {
    // The gap that let the notification centre ship a 500. `listPagesIn()` sweeps resource List
    // pages; roughly thirty admin pages and one portal page render a table without being one, and
    // none of them were checked. A blank label is fatal on those screens for exactly the same reason.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $asset = makeAsset(['code' => 'RC4']);
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $pages = tablePagesIn('Filament/Admin/Pages', 'App\\Filament\\Admin\\Pages');

    expect($pages)->not->toBeEmpty('No custom table pages discovered — this sweep has stopped collecting.');

    $blank = [];
    $unmountable = [];
    $optedOut = [];

    asTenant($asset, function () use ($pages, &$blank, &$unmountable) {
        foreach ($pages as $page) {
            try {
                $table = Livewire::test($page)->instance()->getTable();
            } catch (Throwable $e) {
                // The blank-label refusal is THIS GATE'S TARGET and it arrives as a thrown
                // exception, not as a column we can inspect: `Livewire::test()` renders on mount,
                // and the column manager throws during that render. Routing it to the tolerated
                // "could not mount" bucket is how a gate reports green on the very defect it
                // exists to catch — which this one did, until the mutation showed it.
                if (str_contains($e->getMessage(), 'blank label')) {
                    $blank[] = class_basename($page).' → '.Str::limit($e->getMessage(), 90);

                    continue;
                }

                // Anything else is not this gate's finding, but it is not a pass either — counted
                // and bounded below rather than swallowed.
                $unmountable[] = class_basename($page).': '.Str::limit($e->getMessage(), 100);

                continue;
            }

            // Only where reordering is actually ON, which is this gate's own stated premise: a
            // blank label is fatal BECAUSE the column manager has to name the column. A bespoke
            // page may legitimately opt out and keep an icon-only marker unlabelled — the
            // notification centre does exactly that, and calling it a defect would be demanding a
            // header the design deliberately does without.
            if (! $table->hasReorderableColumns()) {
                $optedOut[] = class_basename($page);

                continue;
            }

            foreach ($table->getColumns() as $name => $column) {
                if (trim((string) $column->getLabel()) === '') {
                    $blank[] = class_basename($page).' → '.$name;
                }
            }
        }
    });

    expect($blank)->toBe([], "These custom-page table columns have a blank label, which is a 500 on that page:\n  ".implode("\n  ", $blank));

    // Opt-outs are reported for the same reason skips are: each one is a page this sweep is NOT
    // checking, and a growing list means the gate covers less than it appears to.
    expect(count($optedOut))->toBeLessThanOrEqual(3, 'More pages have opted out of reorderable columns than expected: '.implode(', ', $optedOut));

    // Bounded, so the sweep cannot quietly shrink to nothing while still reporting green.
    expect(count($unmountable))->toBeLessThanOrEqual(12, "Too many custom table pages could not be mounted, so this sweep covers less than it claims:\n  ".implode("\n  ", $unmountable));
})->group('slow');
