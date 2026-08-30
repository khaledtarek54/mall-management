<?php

use App\Support\Navigation;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Filament\Forms\Components\Hidden;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentIcon;
use Filament\Tables\Table;

/**
 * Nothing in the Arabic panel reads in English by accident.
 *
 * ## Found by an operator, not by a test
 *
 * Two screens shipped with English group headings above Arabic rows — *"Request type: access"* on
 * the tenant-request subcategories and *"Type: cause"* on the failure codes. Filament derives BOTH
 * halves of a group heading from the raw column when nothing says otherwise: the label kebabbed
 * into English, the title as the database spelling. Eight groups across seven resources had it.
 *
 * Pulling that thread found the bigger one. `__()` takes `(string $key, array $replace, ?string
 * $locale)` — the third argument is the LOCALE — and eight call sites passed a fallback VALUE
 * there. Laravel is asked for the key in a locale called *"Properties"*, finds none, and serves the
 * **fallback locale**: English. The entire Roles & Permissions form rendered in English on the
 * Arabic panel for that one reason, ~110 strings on a single screen, and the intended fallback
 * never worked either (a missing key renders as the key, not as the value). `App\Support\Translate`
 * exists so the intent has somewhere correct to live.
 *
 * ## What this checks, and what it deliberately does not
 *
 * It builds every resource's table and create form under `ar` and looks for CHROME that contains
 * Latin letters and no Arabic. Chrome only — labels, headings, group titles. Never cell VALUES: a
 * tenant called "Cilantro" and a vendor called "CleanFleet Janitorial" are operator data and are
 * supposed to read exactly as typed. That distinction is the whole difficulty of this check, and it
 * is drawn structurally rather than by keeping a list of allowed words.
 *
 * Three exclusions, each because the string is not visible to a reader:
 *  - `Hidden` fields, and anything `isHidden()` or `isLabelHidden()`. A `CheckboxList->label('')`
 *    is NOT one of these — a blank label makes Filament derive an English one from the field name,
 *    which is how "Permissions module assets" appeared above every checkbox group. Use
 *    `hiddenLabel()`.
 *  - `Tabs` CONTAINERS. Their label is rendered only as the strip's `aria-label`, never as text.
 *    Six carry a machine name; that is an accessibility nicety, not the defect an operator sees,
 *    and it is recorded here so the next reader does not re-derive it as a bug.
 *  - Acronyms identical in both languages — "PDF" is "PDF" in Arabic.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset, isQuiet: true);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app()->setLocale('ar');
});

afterEach(fn () => app()->setLocale('en'));

/** Latin letters, no Arabic — i.e. a string nobody translated. */
function readsAsEnglish(mixed $text): bool
{
    if (! is_string($text) || trim($text) === '') {
        return false;
    }

    // Identical in both languages; translating them would be the error.
    if (in_array(trim($text), ['PDF', 'CSV', 'XLSX', 'VAT', 'CAM', 'SLA', 'ID', 'QR', 'URL'], true)) {
        return false;
    }

    return preg_match('/[A-Za-z]/', $text) === 1
        && preg_match('/[\x{0600}-\x{06FF}]/u', $text) !== 1;
}

/** Every admin resource whose table and form can be built. */
function buildableResources(): array
{
    return array_values(array_filter(
        Filament::getPanel('admin')->getResources(),
        fn (string $resource): bool => ($resource::getPages()['index'] ?? null) !== null,
    ));
}

/**
 * Every action a table renders, with `ActionGroup`s flattened.
 *
 * A group's children are the actions an operator actually reads; the group itself usually carries
 * no label at all. Walking only the top level would sweep the two grouped resources and find
 * nothing in them.
 *
 * @return array<int, object>
 */
function tableActionsOf(Table $table): array
{
    $flat = [];

    $walk = function (array $items) use (&$walk, &$flat): void {
        foreach ($items as $item) {
            if (method_exists($item, 'getActions')) {
                try {
                    $walk($item->getActions());
                } catch (Throwable) {
                    // an unresolvable group; its own label is swept below if it has one
                }
            }

            if (method_exists($item, 'getName')) {
                $flat[] = $item;
            }
        }
    };

    foreach (['getRecordActions', 'getToolbarActions', 'getHeaderActions'] as $accessor) {
        if (! method_exists($table, $accessor)) {
            continue;
        }

        try {
            $walk($table->{$accessor}());
        } catch (Throwable) {
            continue;
        }
    }

    return $flat;
}

it('translates every worklist tab and every empty state', function () {
    // Two surfaces no gate has ever read. A tab strip is the first thing on a list page and an
    // empty state is the ONLY thing on it when there is nothing to show — the two moments where an
    // English word is most conspicuous and least likely to be noticed by whoever wrote it.
    $english = [];
    $checked = 0;

    foreach (buildableResources() as $resource) {
        $page = $resource::getPages()['index']->getPage();
        $short = class_basename($resource);

        try {
            $table = $resource::table(Table::make(new $page));
        } catch (Throwable) {
            continue;
        }

        foreach (['getEmptyStateHeading', 'getEmptyStateDescription'] as $accessor) {
            if (! method_exists($table, $accessor)) {
                continue;
            }

            $checked++;

            try {
                $text = $table->{$accessor}();
            } catch (Throwable) {
                continue;
            }

            if (readsAsEnglish($text === null ? null : (string) $text)) {
                $english[] = "{$short} {$accessor} → {$text}";
            }
        }

        if (! method_exists($page, 'getTabs')) {
            continue;
        }

        try {
            $tabs = (new $page)->getTabs();
        } catch (Throwable) {
            continue;
        }

        foreach ($tabs as $key => $tab) {
            $checked++;

            try {
                $label = $tab->getLabel();
            } catch (Throwable) {
                continue;
            }

            if (readsAsEnglish($label)) {
                $english[] = "{$short} tab[{$key}] → {$label}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(200);

    expect($english)->toBe([], "Reads in English on the Arabic panel:\n  ".implode("\n  ", $english));
});

it('translates the TENANT and CONTRACTOR panels too, not just the operator one', function () {
    // Everything above sweeps `Filament::getPanel('admin')`. The tenant portal and the contractor
    // portal are the two surfaces read by people OUTSIDE the operator's office — a retailer's
    // accountant and a maintenance contractor — and neither was covered by anything.
    $english = [];
    $checked = 0;
    $sweptPerPanel = [];

    foreach (['portal', 'vendor'] as $panelId) {
        $panel = Filament::getPanel($panelId);
        Filament::setCurrentPanel($panel);
        $sweptPerPanel[$panelId] = 0;

        foreach ($panel->getResources() as $resource) {
            $page = ($resource::getPages()['index'] ?? null)?->getPage();

            if ($page === null) {
                continue;
            }

            $short = $panelId.'/'.class_basename($resource);

            try {
                $table = $resource::table(Table::make(new $page));
            } catch (Throwable) {
                continue;
            }

            $sweptPerPanel[$panelId]++;

            foreach ($table->getColumns() as $column) {
                $checked++;
                if (readsAsEnglish($label = $column->getLabel())) {
                    $english[] = "{$short} column {$column->getName()} → {$label}";
                }
            }

            foreach ($table->getFilters() as $filter) {
                $checked++;
                if (readsAsEnglish($label = $filter->getLabel())) {
                    $english[] = "{$short} filter {$filter->getName()} → {$label}";
                }
            }

            foreach (tableActionsOf($table) as $action) {
                $checked++;

                try {
                    $label = $action->getLabel();
                } catch (Throwable) {
                    continue;
                }

                if (readsAsEnglish($label)) {
                    $english[] = "{$short} action {$action->getName()} → {$label}";
                }
            }
        }
    }

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    // The premise, per panel rather than in total. A count alone would stay satisfied by the
    // portal's nine resources while the contractor panel silently stopped being swept at all —
    // which is exactly how a sweep goes blind without anyone noticing.
    expect($sweptPerPanel['portal'])->toBeGreaterThan(5)
        ->and($sweptPerPanel['vendor'])->toBeGreaterThan(0)
        ->and($checked)->toBeGreaterThan(80);

    expect($english)->toBe([], "A tenant or a contractor reads this in English on the Arabic panel:\n  "
        .implode("\n  ", $english));
});

it('translates every table label, filter and action', function () {
    $english = [];
    $checked = 0;

    foreach (buildableResources() as $resource) {
        $page = $resource::getPages()['index']->getPage();

        try {
            $table = $resource::table(Table::make(new $page));
        } catch (Throwable) {
            continue;
        }

        foreach ($table->getColumns() as $column) {
            $checked++;
            if (readsAsEnglish($label = $column->getLabel())) {
                $english[] = class_basename($resource)." column {$column->getName()} → {$label}";
            }
        }

        foreach ($table->getFilters() as $filter) {
            $checked++;
            if (readsAsEnglish($label = $filter->getLabel())) {
                $english[] = class_basename($resource)." filter {$filter->getName()} → {$label}";
            }
        }

        // ── The ACTIONS this test has always claimed to check ────────────────────────────────
        //
        // The title said "table label, filter and action" from the day it was written and the body
        // checked two of the three. Nothing was wrong with the actions — 221 of them read in
        // Arabic when this was finally measured — but nothing was keeping them that way, which is
        // the same shape as every other gate in this suite that was found checking a weaker
        // property than its name.
        //
        // The MODAL chrome is swept with them: a button can be perfectly translated and open a
        // confirmation written in English, and the modal is where the consequence is stated.
        foreach (tableActionsOf($table) as $action) {
            foreach (['getLabel', 'getModalHeading', 'getModalDescription', 'getModalSubmitActionLabel'] as $accessor) {
                if (! method_exists($action, $accessor)) {
                    continue;
                }

                $checked++;

                try {
                    $text = $action->{$accessor}();
                } catch (Throwable) {
                    continue; // needs a record or a mounted container; not evaluable here
                }

                if (readsAsEnglish($text)) {
                    $english[] = class_basename($resource)." action {$action->getName()}::{$accessor} → {$text}";
                }
            }
        }
    }

    // The premise. A sweep that built nothing would report no English and pass.
    expect($checked)->toBeGreaterThan(300);

    expect($english)->toBe([], "Reads in English on the Arabic panel:\n  ".implode("\n  ", $english));
});

it('translates both halves of every table group heading', function () {
    // Filament renders a group heading as `label: title` and derives BOTH from the raw column.
    // `App\Support\Filament\TableGroup::byColumn()` takes them from the column's own definition, so
    // there is never a second spelling of a value to keep in step.
    $english = [];
    $checked = 0;

    foreach (buildableResources() as $resource) {
        $page = $resource::getPages()['index']->getPage();

        try {
            $table = $resource::table(Table::make(new $page));
        } catch (Throwable) {
            continue;
        }

        $groups = $table->getGroups();

        if ($default = $table->getDefaultGroup()) {
            $groups[] = $default;
        }

        foreach ($groups as $group) {
            $checked++;

            if (readsAsEnglish($label = $group->getLabel())) {
                $english[] = class_basename($resource).' group '.$group->getId()." label → {$label}";
            }

            // The TITLE is only checked where the grouped column is a CLASSIFICATION. Grouping by
            // `tenant.name` yields "Cilantro", which is a tenant, not an untranslated string —
            // and the difference is read off the column name rather than off a list of exceptions.
            if (str_contains($group->getId(), '.')) {
                continue;
            }

            $record = $resource::getModel()::query()->first();

            if ($record === null || data_get($record, $group->getId()) === null) {
                continue;
            }

            if (readsAsEnglish($title = $group->getTitle($record))) {
                $english[] = class_basename($resource).' group '.$group->getId()." title → {$title}";
            }
        }
    }

    expect($checked)->toBeGreaterThan(5);

    expect($english)->toBe([], "A group heading reads in English on the Arabic panel. Build it with\n"
        ."App\Support\Filament\TableGroup::byColumn(\$table, '<column>') so it takes its label and its\n"
        ."values from the column's own definition:\n  ".implode("\n  ", $english));
});

it('translates every visible form label and section heading', function () {
    $english = [];
    $checked = 0;
    $unevaluated = 0;

    foreach (buildableResources() as $resource) {
        $createPage = ($resource::getPages()['create'] ?? null)?->getPage();

        if ($createPage === null) {
            continue;
        }

        try {
            $components = $resource::form(Schema::make(new $createPage))->getFlatComponents(withHidden: true);
        } catch (Throwable) {
            continue;
        }

        foreach ($components as $component) {
            // Not visible to a reader — see the class docblock for why each is excluded.
            if ($component instanceof Hidden || $component instanceof Tabs) {
                continue;
            }

            foreach (['isHidden', 'isLabelHidden'] as $method) {
                if (method_exists($component, $method)) {
                    try {
                        if ($component->{$method}()) {
                            continue 2;
                        }
                    } catch (Throwable) {
                    }
                }
            }

            foreach (['getLabel', 'getHeading'] as $method) {
                if (! method_exists($component, $method)) {
                    continue;
                }

                try {
                    $text = $component->{$method}();
                } catch (Throwable) {
                    // COUNTED, not swallowed. `Repeater::getLabel()` throws outside a mounted
                    // container ("Call to a member function hasAttribute() on null"), and the first
                    // version of this gate caught that and moved on — so it silently skipped every
                    // repeater in the panel while reporting a clean sweep. Exactly the failure this
                    // codebase names "a gate can report on a set it has silently stopped
                    // collecting", introduced by the gate written to prevent it.
                    $unevaluated++;

                    continue;
                }

                $checked++;

                if (is_string($text) && readsAsEnglish($text)) {
                    $english[] = class_basename($resource).' → '.trim($text);
                }
            }
        }
    }

    expect($checked)->toBeGreaterThan(300);

    // Components this sweep could not read are reported rather than ignored. Repeaters are the
    // known set (their label cannot be resolved outside a mounted container), which is why
    // `->label('')` on one is caught by the STATIC check below instead.
    expect($unevaluated)->toBeLessThan(60,
        "{$unevaluated} components could not be evaluated — the sweep may be skipping a whole class.");

    expect($english)->toBe([], "Reads in English on the Arabic panel:\n  ".implode("\n  ", $english));
});

it('never blanks a label where Filament would derive an English one', function () {
    // `->label('')` does NOT hide a label — Filament treats blank as unset and DERIVES one from the
    // field name, in English, whatever the panel's language. That is how "Allocations" appeared over
    // the payment repeater and "Permissions module assets" over every checkbox group on the roles
    // form. `->hiddenLabel()` is the one that hides.
    //
    // Checked STATICALLY because the runtime sweep above cannot read a repeater's label at all —
    // which is precisely where three of the five instances were.
    $offenders = [];

    foreach (filamentSources() as $file) {
        // FORM schemas only. A table COLUMN may legitimately carry `label('')` — a coloured-dot
        // column wants no header — and `hiddenLabel()` does not even exist on a column, so
        // "fixing" one is a fatal at render. Found the hard way: converting the notification
        // centre's icon column turned every admin page red in AdminPageSmokeTest.
        if (! str_contains($file, '/Schemas/')) {
            continue;
        }

        if (str_contains((string) file_get_contents($file), "->label('')")) {
            $offenders[] = str_replace(base_path().'/', '', $file);
        }
    }

    expect($offenders)->toBe([], "Use `->hiddenLabel()`; `->label('')` renders a derived English label:\n  "
        .implode("\n  ", $offenders));
});

it('never passes a fallback where __() expects a LOCALE', function () {
    // `__(string $key, array $replace = [], ?string $locale = null)`. Eight call sites passed a
    // fallback VALUE as the third argument:
    //
    //     __("admin.permission_modules.{$module}", [], static::humanize($module))
    //
    // Laravel is then asked for the key in a locale called "Properties", finds no such locale, and
    // falls back to the FALLBACK LOCALE — English. The whole Roles & Permissions form rendered in
    // English on the Arabic panel for this one reason. The intended fallback never worked either:
    // a genuinely missing key returns the KEY, not the value. `App\Support\Translate::orFallback()`
    // is where that intent belongs.
    //
    // A third argument is legitimate ONLY when it really is a locale — a literal 'en'/'ar', or
    // `app()->getLocale()`. The backfill command uses the literal form on purpose.
    $offenders = [];

    $files = array_merge(
        filamentSources(),
        glob(app_path('Support/*.php')) ?: [],
        glob(app_path('Console/Commands/*.php')) ?: [],
        glob(resource_path('views/**/*.blade.php'), GLOB_BRACE) ?: [],
    );

    foreach ($files as $file) {
        // COMMENTS STRIPPED FIRST. Without it this gate reports `App\Support\Translate`, whose
        // docblock quotes the very call it exists to replace — a gate that fails on its own
        // explanation teaches the next person to delete the explanation.
        $source = (string) preg_replace('~/\*.*?\*/|//[^\n]*~s', '', (string) file_get_contents($file));

        // One level of nesting allowed in the third argument: it may legitimately be
        // `app()->getLocale()`, which a flat `[^)]+` truncated to `app(` and then reported.
        if (! preg_match_all('/__\(\s*[^,()]*(?:\([^()]*\))?[^,()]*,\s*\[\s*\]\s*,\s*((?:[^()]|\([^()]*\))+)\)/', $source, $matches)) {
            continue;
        }

        foreach ($matches[1] as $third) {
            $third = trim($third);

            $isLocale = in_array($third, ["'en'", "'ar'", '"en"', '"ar"'], true)
                || str_contains($third, 'getLocale()');

            if (! $isLocale) {
                $offenders[] = str_replace(base_path().'/', '', $file).'  →  '.$third;
            }
        }
    }

    expect($offenders)->toBe([], "The third argument to __() is the LOCALE, not a fallback. Use\n"
        ."App\\Support\\Translate::orFallback()/orHumanized() instead:\n  ".implode("\n  ", $offenders));
});

it('names every screen in Arabic', function () {
    $english = [];

    foreach (Navigation::placed() as $screen) {
        if (readsAsEnglish($label = $screen::getNavigationLabel())) {
            $english[] = class_basename($screen)." → {$label}";
        }
    }

    expect($english)->toBe([], "Sidebar entries reading in English on the Arabic panel:\n  ".implode("\n  ", $english));
})->skip(fn () => ! class_exists(FilamentIcon::class), 'needs Filament');
