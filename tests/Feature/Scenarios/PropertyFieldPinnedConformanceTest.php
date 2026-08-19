<?php

use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\CashFlow;
use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Filament\Admin\Pages\VatReturn;
use App\Filament\Admin\Resources\Expenses\ExpenseResource;
use App\Support\Filament\PropertyField;
use App\Support\PropertyIsolation;
use App\Support\TenantScope;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The property picker shows the answer; it never asks a question it cannot honour.
 *
 * ## Why a gate, when nothing here was ever a leak
 *
 * It was never possible to write a row into another mall, or into no mall at all, from these
 * screens. `assertAssetInScope()` refuses a blank property as `(int) null === 0` and `EntitySelect`
 * refuses a foreign one at validation, for every role — a real property being selected makes
 * `visibleAssetIds()` exactly `[currentId]`, so both are already airtight. What the screens did was
 * OFFER those options anyway: fill in a journal entry, pick "Consolidated (all)", press Create, and
 * meet a 403 with nothing explaining it.
 *
 * That is the failure this gate exists for, and it is not the kind a leak test can see. Every other
 * property-isolation check asks "can this write escape?"; the answer was already no. This one asks
 * whether the control tells the operator the truth about what it will accept.
 *
 * The reports were the worse half. `TenantScope::reportAssetIds()` clamps its argument to the
 * visible set, so on a trial balance "Consolidated (all)" and "the mall next door" both resolved
 * silently to the mall you were standing in. Right figures under a wrong caption — and nobody
 * re-checks a total they believe they asked for.
 *
 * ## What it checks, and how
 *
 * By RENDERING each create form and reading the component's evaluated state, not by grepping for
 * `PropertyField::make()`. A call site can chain `->disabled(false)` after the shared component and
 * look perfectly correct in source; only the built component knows what the operator will see. Same
 * reasoning as Test D in `PropertyIsolationConformanceTest`, whose machinery this borrows.
 *
 * Opting out means adding the screen to {@see PropertyField::PORTFOLIO_LEVEL} with a reason, and a
 * stale entry there fails too — an exemption for a field that is already pinned is a claim nobody
 * has re-read.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/** Every Filament admin Resource. Inline rather than a file-scope helper: a parallel worker loads only its own files. */
$adminResources = function (): array {
    $base = app_path('Filament/Admin/Resources');

    return collect(glob($base.'/*/*Resource.php') ?: [])
        ->map(fn ($f) => 'App\\Filament\\Admin\\Resources\\'.str_replace('/', '\\', str_replace([$base.'/', '.php'], '', $f)))
        ->filter(fn ($c) => class_exists($c) && is_subclass_of($c, Resource::class))
        ->values()
        ->all();
};

/** The resource directories whose property field is deliberately free — derived from the register's paths. */
$exemptDirectories = fn (): array => collect(array_keys(PropertyField::PORTFOLIO_LEVEL))
    ->map(fn (string $path) => basename(dirname(dirname($path))))
    ->all();

/**
 * Mount a create page and hand back its `asset_id` component, or null when the form has none
 * (Custody derives its property from the chosen employee — not this gate's concern).
 */
$assetFieldOfCreateForm = function (string $resource) {
    $create = $resource::getPages()['create'] ?? null;

    if ($create === null) {
        return null;
    }

    return Livewire::test($create->getPage())->instance()->form->getComponent('asset_id');
};

it('pins every document create form to the property the operator is standing in', function () use ($adminResources, $exemptDirectories, $assetFieldOfCreateForm) {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $mall = makeAsset(['code' => 'HW']);
    Filament::setTenant($mall);

    $exempt = $exemptDirectories();
    $offenders = [];
    $checked = 0;

    try {
        foreach ($adminResources() as $resource) {
            if (! PropertyIsolation::isOwned($resource::getModel())) {
                continue;
            }

            $directory = class_basename(dirname((new ReflectionClass($resource))->getFileName()));
            if (in_array($directory, $exempt, true)) {
                continue;
            }

            $field = $assetFieldOfCreateForm($resource);
            if ($field === null) {
                continue;
            }

            $checked++;

            // Three separate promises, and a form can keep two while breaking the third: a pinned
            // field that is not dehydrated saves NO property at all (a disabled input is not
            // submitted), and a disabled field left at its old default names the wrong mall.
            $faults = [];
            if (! $field->isDisabled()) {
                $faults[] = 'editable';
            }
            if (! $field->isDehydrated()) {
                $faults[] = 'not dehydrated (the pinned value would never be saved)';
            }
            if ((int) $field->getState() !== $mall->id) {
                $faults[] = 'defaults to '.var_export($field->getState(), true).', not the selected mall';
            }

            if ($faults !== []) {
                $offenders[] = class_basename($resource).' ('.implode('; ', $faults).')';
            }
        }
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }

    // A sweep that matched nothing passes every assertion below it. Assert it ran first.
    expect($checked)->toBeGreaterThan(15, 'The sweep found almost no create forms with an asset_id — it is checking nothing.');

    expect($offenders)->toBe(
        [],
        'These create forms let the operator change or clear the property while a mall is selected. '
            ."Every option other than the selected mall is refused server-side anyway — a blank by \n"
            .'assertAssetInScope() with a bare 403, a foreign one by validation — so the control only '
            ."offers dead ends. Build the field with App\\Support\\Filament\\PropertyField::make() \n"
            .'(pass any extra lock as $alsoDisabledWhen — chaining ->disabled() after it silently '
            ."unpins the field), or register the screen in PropertyField::PORTFOLIO_LEVEL with a \n"
            .'reason: '.implode(', ', $offenders),
    );
});

it('keeps the portfolio-level register honest', function () use ($adminResources, $assetFieldOfCreateForm) {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset(['code' => 'HW']));

    try {
        expect(PropertyField::PORTFOLIO_LEVEL)->not->toBeEmpty();

        foreach (PropertyField::PORTFOLIO_LEVEL as $path => $reason) {
            expect(file_exists(base_path($path)))->toBeTrue(
                "PropertyField::PORTFOLIO_LEVEL names {$path}, which no longer exists. A moved or "
                    .'deleted screen leaves an exemption protecting nothing.',
            );

            // Long enough to have been thought about. "Portfolio-level" is not a reason.
            expect(str_word_count($reason))->toBeGreaterThan(15, "The reason for exempting {$path} is too thin to review.");
        }

        // And the exemption must still be LOAD-BEARING: if the screen's field turned out to be
        // pinned after all, the entry is a stale claim and the screen belongs back under the gate.
        $stale = [];
        foreach ($adminResources() as $resource) {
            $directory = class_basename(dirname((new ReflectionClass($resource))->getFileName()));

            $exemptHere = collect(array_keys(PropertyField::PORTFOLIO_LEVEL))
                ->contains(fn (string $path) => basename(dirname(dirname($path))) === $directory);

            if (! $exemptHere) {
                continue;
            }

            $field = $assetFieldOfCreateForm($resource);

            if ($field !== null && $field->isDisabled()) {
                $stale[] = class_basename($resource);
            }
        }

        expect($stale)->toBe([], 'Exempted in PropertyField::PORTFOLIO_LEVEL but the property field is pinned anyway — drop the exemption: '.implode(', ', $stale));
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }
});

it('pins the property scope on every financial statement', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $mall = makeAsset(['code' => 'HW']);
    Filament::setTenant($mall);

    $offenders = [];

    try {
        foreach ([TrialBalance::class, BalanceSheet::class, IncomeStatement::class, CashFlow::class, VatReturn::class, GeneralLedger::class] as $page) {
            $component = Livewire::test($page);
            $field = $component->instance()->filtersForm(app(Schema::class)->livewire($component->instance()))
                ->getComponent('assetId');

            $faults = [];
            if ($field === null) {
                $faults[] = 'has no assetId control at all';
            } else {
                if (! $field->isDisabled()) {
                    $faults[] = 'editable';
                }
                if ((int) $component->instance()->assetId !== $mall->id) {
                    $faults[] = 'assetId is '.var_export($component->instance()->assetId, true).', not the selected mall';
                }
            }

            if ($faults !== []) {
                $offenders[] = class_basename($page).' ('.implode('; ', $faults).')';
            }
        }
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }

    expect($offenders)->toBe(
        [],
        'These statements still ask which property to report on. reportAssetIds() clamps every '
            .'answer back to the selected mall, so the control changes the caption and not the '
            .'figures: '.implode(', ', $offenders),
    );
});

it('accounts for every property control in the panel', function () {
    // The rendered sweep above visits CREATE PAGES. That is where the pin matters most and it is
    // also, on its own, a blind spot shaped exactly like the one this whole change was about: a
    // relation manager, a table filter, a header-action form and a page filter strip all declare
    // property controls in directories a create-page sweep never opens, and each would have gone on
    // looking correct forever.
    //
    // So this is the coarser, complete half — source-level, every file, no exceptions that are not
    // written down. It cannot tell a pinned field from an unpinned one (that is the rendered
    // sweep's job); it can tell whether somebody DECIDED.
    $unaccounted = [];

    foreach ([base_path('app/Filament')] as $root) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

        foreach ($files as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $source = (string) file_get_contents($file->getPathname());

            if (! str_contains($source, "make('asset_id')") && ! str_contains($source, "make('assetId')")) {
                continue;
            }

            $relative = str_replace(base_path().'/', '', $file->getPathname());

            if (str_contains($source, 'PropertyField::')
                || array_key_exists($relative, PropertyField::UNPINNED)
                || array_key_exists($relative, PropertyField::PORTFOLIO_LEVEL)) {
                continue;
            }

            $unaccounted[] = $relative;
        }
    }

    sort($unaccounted);

    expect($unaccounted)->toBe(
        [],
        'These files declare a property control that is neither built by PropertyField nor '
            .'registered as a deliberate exception. Build it with PropertyField::make(), or add it '
            .'to PropertyField::UNPINNED with the reason it is not a pinnable picker: '
            .implode(', ', $unaccounted),
    );

    // Both registers must still describe real files — a moved screen leaves an entry protecting
    // nothing, and reads as coverage.
    foreach ([PropertyField::UNPINNED, PropertyField::PORTFOLIO_LEVEL] as $register) {
        foreach ($register as $path => $reason) {
            expect(file_exists(base_path($path)))->toBeTrue("A property-control register names {$path}, which no longer exists.");
            expect(str_word_count($reason))->toBeGreaterThan(15, "The reason recorded for {$path} is too thin to review.");
        }
    }
});

it('carries the pin onto every edit form for free', function () {
    // The rendered sweep only mounts CREATE pages, and manufacturing a valid record for each of the
    // 38 editable resources is a fixture project rather than a test. It does not need to be: both
    // pages read `XResource::form()`, so an edit form is the same built schema the sweep already
    // inspected — `default()` simply does not fire, and the record's own property loads disabled.
    //
    // That inheritance is the whole argument, so assert it rather than assume it. An Edit page
    // declaring its own form()/getFormSchema()/content() would step outside the gate silently, and
    // this is the one line that notices.
    $overriding = [];

    foreach (glob(app_path('Filament/Admin/Resources/*/Pages/Edit*.php')) ?: [] as $file) {
        $source = (string) file_get_contents($file);

        foreach (['function form(', 'function getFormSchema(', 'function content('] as $override) {
            if (str_contains($source, $override)) {
                $overriding[] = basename($file).' declares '.rtrim($override, '(').'()';
            }
        }
    }

    expect($overriding)->toBe(
        [],
        'These Edit pages build their own schema instead of the resource\'s, so the create-form '
            .'sweep says nothing about them and their property field is unverified: '.implode(', ', $overriding),
    );
});

it('proves the refusals these pinned controls stand in for', function () {
    // The pin is a UI truth, not a guard, and this is the pairing that says so: remove the field
    // entirely and the server still refuses both. If either of these ever passes, the pin above
    // became the only thing standing between an operator and another mall's books.
    $this->actingAs(makeUser('super_admin'));
    $mall = makeAsset(['code' => 'HW']);
    $other = makeAsset(['code' => 'XX']);
    Filament::setTenant($mall);

    try {
        expect(TenantScope::visibleAssetIds())->toBe([$mall->id]);

        // A blank property — what "Consolidated (all)" submitted.
        expect(fn () => ExpenseResource::assertAssetInScope(null))
            ->toThrow(HttpException::class);

        // A neighbouring mall.
        expect(fn () => ExpenseResource::assertAssetInScope($other->id))
            ->toThrow(HttpException::class);

        // The control: the selected mall is accepted. Without this the two refusals above would
        // pass just as happily against a guard that refused everything.
        ExpenseResource::assertAssetInScope($mall->id);

        // And the reports' own clamp, which is what made their picker cosmetic.
        expect(TenantScope::reportAssetIds(null))->toBe([$mall->id]);
        expect(TenantScope::reportAssetIds($other->id))->toBe([$mall->id]);
    } finally {
        Filament::setTenant(null, isQuiet: true);
    }
});
