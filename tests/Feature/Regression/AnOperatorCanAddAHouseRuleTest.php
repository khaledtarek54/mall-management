<?php

use App\Filament\Admin\Resources\Violations\Pages\CreateViolation;
use App\Models\Violation;
use App\Models\ViolationCategory;
use App\Services\BillViolationFineService;
use App\Support\ValueSets;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\ViolationCategorySeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **The mall's house rules are a register the operator revises, end to end.**
 *
 * `Violation::CATEGORIES` held seven values and its own migration had promised, in writing, that
 * "the operator's set of violation types is theirs to extend without a migration". It was not:
 * adding "blocked fire exit" meant a PHP const, two lang catalogues, a form, a filter and a deploy.
 * And `violations.category` had no `ValueSets` entry at all, so the column accepted anything — a
 * typo saved cleanly and then matched no filter and no repeat-offender report.
 *
 * The three halves that must all be true for a catalogue to be real, each one a place a previous
 * catalogue broke:
 *
 * - the picker OFFERS it (EG-11 converted 3 of 19 surfaces and the rest stayed static);
 * - the column ACCEPTS it (`ValueSets::allowed()` widened and `forTable()` did not, so the picker
 *   offered a value the saving listener refused);
 * - everything downstream LABELS it (a code with no lang key rendered as its raw key).
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'HR']);
    $this->tenant = makeTenant(['name' => 'Rule Breaker Retail']);
});

it('offers, accepts and labels a rule the operator adds', function () {
    ViolationCategory::create([
        'code' => 'fire_exit',
        'name_en' => 'Blocked fire exit',
        'name_ar' => 'مخرج طوارئ مسدود',
        'default_fine_amount' => 5000,
    ]);

    // OFFERED, beside the shipped seven — which are still values the column accepts, and were never
    // retired. The floor applies PER CODE: a shipped code stays offered until a ROW says otherwise.
    //
    // This assertion used to read `toBe(['fire_exit' => …])` on the theory that the catalogue
    // answers with its rows once it has any. That rows-first rule cost a working screen: the rail
    // catalogue seeds `bank_transfer` and no `bank`, so on every seeded install `bank` dropped out
    // of the deposit picker while remaining an accepted value — and both deposit forms default to
    // it, so Filament refused the submit as INVALID on a field nobody had touched.
    expect(ViolationCategory::options())->toHaveKey('fire_exit')
        ->and(ViolationCategory::options()['fire_exit'])->toBe('Blocked fire exit')
        ->and(ViolationCategory::options())->toHaveKey('signage');

    // …and `is_active` still means something, which is what rows-first was protecting. A code the
    // operator RETIRES has a row saying so, and is dropped.
    ViolationCategory::create([
        'code' => 'noise', 'name_en' => 'Noise', 'name_ar' => 'إزعاج', 'is_active' => false,
    ]);

    expect(ViolationCategory::options())->not->toHaveKey('noise')
        // …while the column still accepts it and it still labels, so its history reads.
        ->and(ValueSets::allowed('violations', 'category'))->toContain('noise')
        ->and(ViolationCategory::labelFor('noise'))->toBe('Noise');

    // …and OFFERED BY THE REAL FORM. Asserting the model alone leaves the actual `Select` free to
    // carry a hard-coded literal — no `__()` for the grep gate to see, and `ResourceFormSmokeTest`
    // only proves the page mounts. That is exactly the shape of the regression this case names.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);

    $offered = Livewire::test(CreateViolation::class)
        ->instance()
        ->form
        ->getComponent('category')
        ->getOptions();

    expect($offered)->toHaveKey('fire_exit')
        ->and($offered['fire_exit'])->toBe('Blocked fire exit');

    Filament::setTenant(null, isQuiet: true);

    // ACCEPTED — the saving listener reads `forTable()`, not `allowed()`, and only one `widen()`
    // feeds both.
    expect(ValueSets::allowed('violations', 'category'))->toContain('fire_exit')
        ->and(ValueSets::forTable('violations')['category'])->toContain('fire_exit');

    $violation = Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'category' => 'fire_exit',
        'description' => 'Rear corridor exit blocked by stock.',
        'violation_date' => now()->toDateString(),
    ]);

    // LABELLED — never the raw key, which is what `__("admin.violations.categories.fire_exit")`
    // would have printed on the very screen whose filter lists it.
    expect(ViolationCategory::labelFor($violation->category))->toBe('Blocked fire exit');

    app()->setLocale('ar');
    expect(ViolationCategory::labelFor($violation->category))->toBe('مخرج طوارئ مسدود');
});

it('refuses a category that is in neither the rule book nor the floor', function () {
    // The control for the test above: the column is now ENFORCED, which it was not before. Without
    // this, "accepts fire_exit" would pass just as happily against a column that accepts anything.
    expect(fn () => Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'category' => 'typo_from_an_import',
        'description' => 'Something',
        'violation_date' => now()->toDateString(),
    ]))->toThrow(DomainException::class);
});

it('keeps the seven shipped rules working before the catalogue is seeded', function () {
    expect(ViolationCategory::query()->count())->toBe(0);

    // The floor. An unseeded database must behave exactly as the const did — the picker offers the
    // seven, labelled from the lang group, and the column takes them.
    expect(ViolationCategory::options())->toHaveKeys(['signage', 'safety', 'other'])
        ->and(ViolationCategory::options()['signage'])->toBe('Signage');

    $violation = Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'category' => 'signage',
        'description' => 'Unauthorised banner.',
        'violation_date' => now()->toDateString(),
    ]);

    expect(ViolationCategory::labelFor($violation->category))->toBe('Signage');
});

it('prefills the standard fine on the form without overwriting a typed one', function () {
    $this->seed(ViolationCategorySeeder::class);
    ViolationCategory::query()->where('code', 'safety')->first()->update(['default_fine_amount' => 7500]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);

    // Driven through the real `->live()` callback, not by calling a helper: a closure in a form
    // schema runs only when an operator touches the field, and this suite otherwise drives services.
    $prefilled = Livewire::test(CreateViolation::class)
        ->set('data.category', 'safety')
        ->assertOk()
        ->assertHasNoFormErrors()
        ->get('data');

    expect((float) $prefilled['fine_amount'])->toBe(7500.0);

    // And the half that matters more: what the officer typed is what stands. The tariff is a
    // starting point, and the amount actually charged is a decision they answer for.
    $typed = Livewire::test(CreateViolation::class)
        ->set('data.fine_amount', 250)
        ->set('data.category', 'safety')
        ->assertOk()
        ->get('data');

    expect((float) $typed['fine_amount'])->toBe(250.0);

    Filament::setTenant(null, isQuiet: true);
});

it('quotes the operator\'s own wording on the fine invoice', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    $this->seed(ChargeCodeSeeder::class);

    ViolationCategory::create([
        'code' => 'fire_exit',
        'name_en' => 'Blocked fire exit',
        'name_ar' => 'مخرج طوارئ مسدود',
    ]);

    $lease = makeLease(makeUnit($this->asset, ['code' => 'H-01']), $this->tenant);

    $violation = Violation::create([
        'asset_id' => $this->asset->id,
        'tenant_id' => $this->tenant->id,
        'category' => 'fire_exit',
        'description' => 'Rear corridor exit blocked by stock.',
        'fine_amount' => 5000,
        'violation_date' => now()->toDateString(),
    ]);

    app(BillViolationFineService::class)->bill($violation);

    $line = $violation->fresh()->billedInvoice->items->first()->description;

    // The words the operator chose, not `admin.violations.categories.fire_exit` — which is what a
    // rule with no lang key would have printed on a document the tenant receives.
    expect($line)->toContain('Blocked fire exit')
        ->and($line)->not->toContain('admin.violations')
        // The lease is what the fine is billed against — named so the assertion above is about the
        // wording on a document that reached a real agreement, not about a string in isolation.
        ->and($violation->fresh()->billedInvoice->lease_id)->toBe($lease->id);
});

it('follows a tariff the operator revises, in the same request', function () {
    // `defaultFineFor()` memoises like every other catalogue read, under its own `fines` suffix. A
    // suffix left out of `catalogueMemoSuffixes()` is never dropped on write — the exact shape of the
    // bug the shared concern was extracted to kill, one memo along. Nothing proved this one.
    $rule = ViolationCategory::create([
        'code' => 'fire_exit',
        'name_en' => 'Blocked fire exit',
        'name_ar' => 'مخرج طوارئ مسدود',
        'default_fine_amount' => 5000,
    ]);

    expect((float) ViolationCategory::defaultFineFor('fire_exit'))->toBe(5000.0);

    $rule->update(['default_fine_amount' => 7500]);

    expect((float) ViolationCategory::defaultFineFor('fire_exit'))->toBe(7500.0);

    // The control: a rule with no tariff answers null rather than the last one read.
    ViolationCategory::create(['code' => 'noise_late', 'name_en' => 'Late noise', 'name_ar' => 'إزعاج ليلي']);

    expect(ViolationCategory::defaultFineFor('noise_late'))->toBeNull();
});
