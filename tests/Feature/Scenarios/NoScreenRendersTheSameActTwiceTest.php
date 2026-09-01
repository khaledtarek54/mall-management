<?php

use App\Models\Asset;
use Database\Seeders\DatabaseSeeder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Livewire\Livewire;

/**
 * **No strip of controls offers the same act twice.**
 *
 * The invoice page rendered "Regenerate payment link" twice (2026-09-01): `EditInvoice` composed
 * `InvoiceActions::all()`, which defines it, AND kept a second inline copy. Both rotated the same
 * token, so neither was wrong — what was wrong is that a DESTRUCTIVE act appeared twice with
 * nothing to say which was which. Reported from the panel.
 *
 * ## Why the source sweep is not enough
 *
 * `AnActIsDeclaredOnceConformanceTest` reads the files and catches that exact shape. It cannot see
 * three others, and each of them renders identically to an operator:
 *
 * - an act supplied by a TRAIT, colliding with one the page declares (the page's source shows one);
 * - an act spread from `parent::getHeaderActions()`;
 * - a group composed at RUNTIME — `LeaseActions::grouped()` resolves `self::only(self::GROUPS[…])`,
 *   so no static read can say what is inside it.
 *
 * This mounts the real component and reads what Filament actually cached, which is the only place
 * all four shapes are visible at once. It is the behavioural half; keep both.
 *
 * ## What counts as one strip
 *
 * What an operator reads as one row of controls: a page header, a table's row actions, its header
 * actions, its toolbar. `ActionGroup` members belong to the strip they sit in — a dropdown is part
 * of the same reading, and putting the same act in two dropdowns of one header is the same defect
 * wearing a hat — so groups are FLATTENED via `getFlatActions()`.
 *
 * ## Names and LABELS, because the operator reads the label
 *
 * Two acts with different names and one label are the same complaint: the header says the same
 * words twice. Labels are only compared where they EVALUATE — a row action's label may be a closure
 * over a record that no list page can supply — and the un-evaluable ones are counted and asserted
 * on, because a sweep that silently skipped them would be reporting on a set it stopped collecting.
 */
beforeEach(function () {
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = Asset::query()
        ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
        ->firstOrFail();

    // super_admin: this asks "what does the screen render", not "who may open it" — and the widest
    // role renders the most acts, which is the set most able to collide.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('renders no act twice on any admin screen', function () {
    $unevaluable = 0;

    /**
     * Flatten one strip to `[name => [labels]]`, descending into groups.
     *
     * A closure, not a file-scope `function`: two test files declaring one helper name is a fatal
     * redeclaration during collection that exits the whole suite 255 with NO output on either
     * stream, and `--parallel` hides it. Four occurrences in this project already.
     */
    $stripNames = function (array $strip) use (&$unevaluable): array {
        $found = [];

        foreach ($strip as $item) {
            $actions = $item instanceof ActionGroup ? $item->getFlatActions() : [$item];

            foreach ($actions as $action) {
                if (! $action instanceof Action) {
                    continue;
                }

                $label = null;

                try {
                    $label = $action->getLabel();
                } catch (Throwable) {
                    // A label closure over a record this strip cannot supply. Counted, not swallowed.
                    $unevaluable++;
                }

                $found[$action->getName()][] = is_string($label) ? $label : null;
            }
        }

        return $found;
    };

    $offenders = [];
    $strips = 0;
    $acts = 0;
    $labelClashes = [];

    /** @var array<string, array{0: string, 1: ?int}> $screens */
    $screens = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();

            if (is_subclass_of($page, ListRecords::class)) {
                $screens[$page] = [$resource, null];

                continue;
            }

            if (! is_subclass_of($page, EditRecord::class)) {
                continue;
            }

            /** @var class-string<Model> $model */
            $model = $resource::getModel();

            $query = method_exists($resource, 'getEloquentQuery') ? $resource::getEloquentQuery() : $model::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $query->withTrashed();
            }

            $record = $query->first() ?? $model::query()->first();

            if ($record !== null) {
                $screens[$page] = [$resource, $record->getKey()];
            }
        }
    }

    // Standalone pages carry header actions too, and several are the busiest screens in the panel.
    foreach (Filament::getPanel('admin')->getPages() as $page) {
        $screens[$page] ??= [null, null];
    }

    // The sweep must find something: one matching zero screens passes for ever while covering
    // nothing, which is how a gate in this project has already stopped gating three times.
    expect(count($screens))->toBeGreaterThan(80);

    $mounted = 0;

    foreach ($screens as $page => [$resource, $key]) {
        try {
            $component = $key === null
                ? Livewire::test($page)
                : Livewire::test($page, ['record' => $key]);

            $instance = $component->instance();

            if (! is_object($instance)) {
                continue;
            }
        } catch (Throwable) {
            // A page that refuses this record, or needs a parameter this sweep cannot supply.
            // ResourceEditFormSmokeTest is what holds "every page mounts"; this one is about
            // duplicates, so an unmountable screen is simply not examined.
            continue;
        }

        $mounted++;

        $found = [];

        if (method_exists($instance, 'getCachedHeaderActions')) {
            $found['header'] = $stripNames($instance->getCachedHeaderActions());
        }

        if (method_exists($instance, 'getTable')) {
            try {
                $table = $instance->getTable();

                $found['row'] = $stripNames($table->getActions());
                $found['table header'] = $stripNames($table->getHeaderActions());
                $found['toolbar'] = $stripNames($table->getToolbarActions());
            } catch (Throwable) {
                // No table on this page.
            }
        }

        foreach ($found as $strip => $names) {
            if ($names === []) {
                continue;
            }

            $strips++;
            $acts += count($names);

            foreach ($names as $name => $labels) {
                if (count($labels) > 1) {
                    $offenders[] = class_basename($page)." · {$strip} strip renders '{$name}' ".count($labels).' times';
                }
            }

            // The other reading of the same defect: different acts, one set of words.
            $seen = [];

            foreach ($names as $name => $labels) {
                foreach (array_filter($labels) as $label) {
                    $seen[$label][] = $name;
                }
            }

            foreach ($seen as $label => $owners) {
                if (count(array_unique($owners)) > 1) {
                    $labelClashes[] = class_basename($page)." · {$strip} strip labels "
                        .implode(' and ', array_unique($owners))." identically: \"{$label}\"";
                }
            }
        }
    }

    // Measured at 159 of 160 screens, 252 strips and 698 acts when this was written; held just
    // under so a screen dropping out of coverage is visible, rather than at a round number nobody
    // re-checks. A sweep silently examining less is how three gates in this project stopped gating.
    expect($mounted)->toBeGreaterThan(150, 'The sweep mounted far fewer screens than it should — coverage has shrunk.');
    expect($strips)->toBeGreaterThan(240, 'Far fewer strips of controls than this panel has.');
    expect($acts)->toBeGreaterThan(650, 'Far fewer acts read than this panel renders.');

    // The label half is only as good as the labels that EVALUATE. Two do not (a closure over a
    // record a list page cannot supply); a jump here means the label comparison quietly stopped
    // covering much of the panel while the name comparison went on passing.
    expect($unevaluable)->toBeLessThanOrEqual(10, 'Too many labels could not be evaluated — the label half of this sweep is covering less than it claims.');

    expect($offenders)->toBe([], implode("\n  ", array_merge(
        ['These screens render the same act more than once in one strip of controls:'],
        $offenders,
    )));

    expect($labelClashes)->toBe([], implode("\n  ", array_merge(
        ['These screens render two different acts under identical words, so the operator cannot',
            'tell them apart:'],
        $labelClashes,
    )));
})->group('slow');
