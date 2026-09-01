<?php

use App\Filament\Admin\Pages\PropertyOverrides;
use App\Support\ValueSets;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

/**
 * A setting you cannot set, and a reminder that arrived after the thing it was reminding about.
 *
 * **The per-property proration override rendered as a NUMERIC box.** Every non-boolean override
 * did, and `billing.proration_method` is one of `actual | thirty_day | year_365 | whole_month` — so
 * the field could not hold its own value. The operator was shown a number box for a word, anything
 * typed was rejected or silently discarded, and the setting was unsettable from the one screen that
 * exists to set it. The boolean case directly above it had already been fixed for exactly this
 * reason and the reasoning was never generalised.
 *
 * **`sales:estimate-missing` ran on the 8th and the chase it follows on the 10th.** The comment
 * above it said "runs a week after the chase, so the tenant has had the reminder and a chance to
 * file first" — for the whole of its life the day said otherwise, so the estimate landed FIRST and
 * the reminder arrived afterwards about a declaration the system had already estimated. Moved to
 * the 17th.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('offers the proration method as a choice, not a number box', function () {
    $page = Livewire::test(PropertyOverrides::class)->instance();

    $field = null;
    foreach ($page->getSchema('form')?->getComponents(withHidden: true) ?? [] as $component) {
        foreach ($component->getChildSchemas() as $child) {
            foreach ($child->getComponents(withHidden: true) as $c) {
                if (str_contains((string) $c->getName(), 'proration_method')) {
                    $field = $c;
                }
            }
        }
    }

    // The premise: the field is on the screen at all.
    expect($field)->not->toBeNull('the proration override is not rendered by PropertyOverrides');

    expect($field)->toBeInstanceOf(Select::class);

    // …and it offers exactly what the column accepts — derived from ValueSets, never a second list.
    expect(array_keys($field->getOptions()))
        ->toBe(ValueSets::allowed('leases', 'proration_method'));
});

it('chases the tenant before it estimates for them', function () {
    $console = file_get_contents(base_path('routes/console.php'));

    preg_match('/sales:scan-missing-declarations.*?monthlyOn\((\d+)/s', $console, $chase);
    preg_match('/sales:estimate-missing.*?monthlyOn\((\d+)/s', $console, $estimate);

    // The premise: both were found, or this compares nothing.
    expect($chase[1] ?? null)->not->toBeNull()
        ->and($estimate[1] ?? null)->not->toBeNull();

    expect((int) $estimate[1])->toBeGreaterThan((int) $chase[1],
        'the estimate must follow the chase — a tenant cannot act on a reminder that arrives after '
        .'the system has already estimated for them');
});
