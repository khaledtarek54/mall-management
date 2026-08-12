<?php

/*
|--------------------------------------------------------------------------
| Regression — Asset form renders + every activity subject has a label
|--------------------------------------------------------------------------
| Two production bugs the Playwright baseline surfaced (2026-07-11):
|
| 1) AssetForm called ->maxLength(7) on a ColorPicker (a method it doesn't
|    have) → the Assets create AND edit pages 500'd for ~6 weeks. Guard: mount
|    both real Livewire pages and assert they render. This catches any future
|    form-component/method misuse on the Asset form, not just this one.
|
| 2) The GL/HR/inventory/fixed-asset modules (21-26) and the maintenance→tenant
|    request rename shipped without adding their `admin.activity.subjects.*`
|    labels, so the Activity Log rendered raw keys like
|    "admin.activity.subjects.journal_entry". Guard: every model that logs
|    activity must have a label for its configured log name, in en AND ar.
*/

use App\Filament\Admin\Resources\Assets\Pages\CreateAsset;
use App\Filament\Admin\Resources\Assets\Pages\EditAsset;
use App\Models\Asset;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders the Asset create page (ColorPicker has no maxLength — must not 500)', function () {
    Filament::setTenant(Asset::query()->where('code', Asset::ALL_PROPERTIES_CODE)->first());

    Livewire::test(CreateAsset::class)->assertSuccessful();
});

it('renders the Asset edit page for a real property', function () {
    $asset = makeAsset(['code' => 'RGN-EDIT']);
    Filament::setTenant($asset);

    Livewire::test(EditAsset::class, ['record' => $asset->getRouteKey()])
        ->assertSuccessful();
});

it('has an activity-subject label (en + ar) for every model that logs activity', function () {
    $missing = [];
    $swept = 0;

    foreach (glob(app_path('Models').'/*.php') as $file) {
        $class = 'App\\Models\\'.pathinfo($file, PATHINFO_FILENAME);
        if (! class_exists($class)) {
            continue;
        }
        // Matched by BASENAME. This filter used to compare against
        // `Spatie\Activitylog\Traits\LogsActivity::class` — **a class that does not exist in
        // the version we ship** (upstream moved it to `Models\Concerns`). `::class` is resolved
        // by the compiler into a plain string and never checks the class exists, so the filter
        // matched nothing, every model was skipped, and this test passed green for a year while
        // sweeping ZERO models — the exact regression it was written to prevent. `$swept` below
        // is what makes that failure mode red instead of invisible.
        $logsActivity = collect(class_uses_recursive($class))
            ->contains(fn (string $trait): bool => class_basename($trait) === 'LogsActivity');
        if (! $logsActivity) {
            continue;
        }
        $swept++;

        // The Activity Log labels rows by their log_name (see ActivityLog page),
        // which each model sets via getActivitylogOptions()->useLogName(...).
        $logName = (new $class)->getActivitylogOptions()->logName
            ?? Str::snake(class_basename($class));

        foreach (['en', 'ar'] as $locale) {
            if (! Lang::has("admin.activity.subjects.{$logName}", $locale, fallback: false)) {
                $missing[] = "{$logName} [{$locale}] (from {$class})";
            }
        }
    }

    // Assert the sweep FOUND something before asserting anything about what it found. Without
    // this line an empty `$missing` means either "all labelled" or "swept nothing", and those
    // two look identical from the outside — which is how this went green for a year.
    expect($swept)->toBeGreaterThan(50)
        ->and($missing)->toBe([]);
});
