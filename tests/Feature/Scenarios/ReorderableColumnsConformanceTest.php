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
