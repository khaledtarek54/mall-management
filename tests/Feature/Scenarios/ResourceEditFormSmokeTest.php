<?php

use App\Models\Asset;
use Database\Seeders\DatabaseSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Livewire\Livewire;

/**
 * **Every resource's Edit page must mount against a REAL record.**
 *
 * The sibling of `ResourceFormSmokeTest`, and the hole it named. That test says, in writing:
 *
 * > *"Create pages only. An Edit page needs a valid, saved record of its own model, and
 * > manufacturing one for fifty models generically is a fixture project that would fail for reasons
 * > unrelated to rendering."*
 *
 * That was true when it was written and is not true now. `DemoSeeder` produces a real, coherent
 * record for essentially every model in the panel — a mall mid-life, with leases, invoices,
 * payments, work orders and a chart that posts. So the Edit sweep does not manufacture anything: it
 * takes a seeded row of each resource's model and opens the page on it. The fixture project
 * dissolved; only the habit of skipping it remained.
 *
 * ## Why this seam specifically
 *
 * Because it is where this project's live 500s came from, and the Create sweep is structurally
 * blind to them. `VendorForm` typed a closure parameter `?Vendor $record` with no import — the
 * vendor EDIT form 500'd, and `ResourceFormSmokeTest` could not see it **because it mounts Create,
 * where that record is legitimately null and the type is never resolved**. `UtilityMetersTable` did
 * the same to a list. A missing `use` is valid PHP that autoloads at CALL time, so it fails only on
 * the path that actually resolves the name.
 *
 * Most resources share one schema class between Create and Edit, so much of this overlaps the
 * Create sweep — the value is entirely in the ones that do not, and those cannot be enumerated in
 * advance. That is the argument for a sweep rather than a list.
 *
 * ## What this does NOT claim
 *
 * **Mount, not interaction.** It asserts the page builds and renders with a record loaded. It would
 * not catch a bug in an `afterStateUpdated` closure — `MoneyFormInteractionSmokeTest` drives those
 * for the forms where a 500 costs most. Saying so plainly matters here more than usual: a gate
 * believed to cover more than it does is worse than none, and this file's own sibling is the
 * project's记 example of that lesson.
 *
 * **Resources with no seeded row are SKIPPED, and counted.** A skip is not a pass, so the count is
 * asserted to stay small: if the demo stops seeding a model, this reports coverage it no longer has,
 * which is the failure mode every register in this codebase is written to avoid.
 */
beforeEach(function () {
    // `DatabaseSeeder`, not `DemoSeeder` alone — and that distinction is the whole coverage.
    //
    // The reference catalogues (chart of accounts, posting map, payment rails, charge codes,
    // expense and retail categories, holidays, approval bands, departments, vendor document types,
    // violation categories, request subcategories, utility tariffs) are laid down by the seeders
    // `DatabaseSeeder` runs BEFORE the demo, exactly as `atriom:install` does on a first deploy.
    // Seeding only the demo left all seventeen of those tables empty, so their Edit pages had no
    // record to open and were silently skipped — a sweep reporting itself healthy while covering
    // three quarters of the panel.
    $this->seed(DatabaseSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $this->asset = Asset::query()
        ->where('code', '!=', Asset::ALL_PROPERTIES_CODE)
        ->firstOrFail();

    // super_admin: this asks "does the form render", not "who may open it".
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('mounts every admin resource Edit form against a seeded record', function () {
    $pages = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();

            if (is_subclass_of($page, EditRecord::class)) {
                $pages[$page] = $resource;
            }
        }
    }

    // The sweep must find something. One matching zero pages would pass for ever while covering
    // nothing — this project has shipped exactly that gate before.
    expect(count($pages))->toBeGreaterThan(25);

    $failed = [];
    $skipped = [];
    $refused = [];
    $opened = 0;

    foreach ($pages as $page => $resource) {
        /** @var class-string<Model> $model */
        $model = $resource::getModel();

        // The RESOURCE's own query, not `$model::query()` — it carries the property scope, and a
        // row outside the selected mall cannot be routed to (`No query results for model [Unit] 1`,
        // which is the panel refusing correctly and reads exactly like a broken page).
        $query = method_exists($resource, 'getEloquentQuery') ? $resource::getEloquentQuery() : $model::query();

        // A soft-deleting resource may only have trashed rows seeded; the Edit page opens those.
        if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
            $query->withTrashed();
        }

        // Several candidates, because a resource's FIRST row may be one the page legitimately
        // refuses — a sent announcement, a locked sales declaration. Refusing a terminal record is
        // the product working, so the sweep looks for a record it will accept before concluding
        // anything about rendering.
        $records = $query->limit(5)->get();

        // A PORTFOLIO-SHARED catalogue has no property dimension, and the resource query returns
        // nothing for it under a selected tenant — so the scoped read alone skipped 17 seeded
        // catalogues (`LedgerAccount` has 168 rows) while the sweep reported itself healthy. Falling
        // back to the plain model query is safe here precisely because those resources are not
        // property-scoped; for one that IS, an out-of-scope record simply fails to route and lands
        // in `skipped` as before, which is the honest answer rather than a false failure.
        if ($records->isEmpty()) {
            $raw = $model::query();

            if (in_array(SoftDeletes::class, class_uses_recursive($model), true)) {
                $raw->withTrashed();
            }

            $records = $raw->limit(5)->get();
        }

        if ($records->isEmpty()) {
            $skipped[] = class_basename($page);

            continue;
        }

        $mounted = false;
        $lastError = null;

        foreach ($records as $record) {
            try {
                Livewire::test($page, ['record' => $record->getKey()])->assertOk();
                $mounted = true;
                break;
            } catch (Throwable $e) {
                $lastError = $e;
            }
        }

        if ($mounted) {
            $opened++;

            continue;
        }

        // A 403 on every candidate is a REFUSAL, not a rendering failure — the page declined the
        // records it was offered. Bucketed separately and reported, never silently passed: a sweep
        // that swallowed 403s would hide a genuine authorization break behind the same symptom.
        str_contains((string) $lastError?->getMessage(), '403')
            ? $refused[] = class_basename($page)
            : $failed[] = class_basename($page).' — '.str($lastError?->getMessage() ?? 'unknown')->limit(200);
    }

    expect($failed)->toBe([], implode("\n  ", array_merge(
        ['These resource Edit forms did not mount on a real record:'],
        $failed,
    )));

    // A skip is not a pass. If the demo stops seeding a model this sweep quietly stops covering it,
    // so the uncovered set is bounded rather than trusted — the same rule every register here keeps.
    expect(count($skipped))->toBeLessThanOrEqual(4, implode("\n  ", array_merge(
        ['Too many Edit pages have no seeded record to open, so this sweep covers less than it says:'],
        $skipped,
    )));

    // Refusals are reported so the set stays visible and reviewable — these are pages whose every
    // seeded record is terminal, which is worth knowing but is not a defect.
    expect(count($refused))->toBeLessThanOrEqual(10, implode("\n  ", array_merge(
        ['These Edit pages refused every seeded record with a 403 — expected for a terminal record,',
            'but a growing list means the sweep is testing less than it appears to:'],
        $refused,
    )));

    // Measured at 56 of 60 when this was written. Held just under it so a resource dropping out of
    // coverage is visible, rather than a round number nobody re-checks.
    expect($opened)->toBeGreaterThan(50, 'The sweep opened far fewer Edit pages than it used to — coverage has silently shrunk.');
});
