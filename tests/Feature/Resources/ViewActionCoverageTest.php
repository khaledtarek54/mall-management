<?php

/**
 * The read-only record view, across every admin table.
 *
 * Reading a record used to mean opening its EDIT form — more friction than the
 * task deserves, and a write surface handed to roles that only hold `.view`.
 *
 * ViewAction takes its schema from the resource's own form rendered through
 * `disabledSchema()`, with `modalSubmitAction(false)`. That is deliberate: the
 * view can never drift from the fields that actually exist, and there is no
 * submit path to dispatch. What still needs asserting is the AUTHZ, because a
 * hidden action is not a gate in Filament — `mountAction()` checks
 * `isDisabled()`, never `isVisible()`.
 */

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\ListRecords;
use Filament\Tables\Table;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
});

/** @return array<int, class-string> */
function vaListPages(): array
{
    return collect(File::allFiles(app_path('Filament/Admin/Resources')))
        ->filter(fn ($f) => str_starts_with($f->getFilename(), 'List') && $f->getExtension() === 'php')
        ->map(function ($f) {
            $rel = str_replace([app_path('Filament/Admin/Resources').'/', '.php'], '', $f->getPathname());

            return 'App\\Filament\\Admin\\Resources\\'.str_replace('/', '\\', $rel);
        })
        ->filter(fn (string $c) => class_exists($c) && is_subclass_of($c, ListRecords::class))
        ->values()
        ->all();
}

it('offers a read-only view on every table whose resource has a form', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin', [$asset->id]));

    $missing = [];
    $found = 0;

    asTenant($asset, function () use (&$missing, &$found) {
        foreach (vaListPages() as $page) {
            $resource = $page::getResource();

            // Three registers (disbursements, statement runs, stock movements)
            // declare no form of their own, so there is no schema for a view
            // modal to render — and their tables already show the whole row.
            // Skipped on purpose.
            //
            // method_exists() is useless here: form() is inherited from the base
            // Resource, so it is always present. Only a resource that DECLARES
            // its own form has a schema worth rendering.
            if ((new ReflectionMethod($resource, 'form'))->getDeclaringClass()->getName() !== $resource) {
                continue;
            }

            /** @var Table $table */
            $table = Livewire::test($page)->instance()->getTable();

            $hasView = collect($table->getRecordActions())
                ->contains(fn ($action) => $action instanceof ViewAction);

            $hasView ? $found++ : $missing[] = class_basename($page);
        }
    });

    // Reported, not silently tolerated: a resource added later without one
    // shows up here by name.
    expect($missing)->toBe([], 'Tables with a form but no read-only view: '.implode(', ', $missing));
    expect($found)->toBeGreaterThan(30);
});

it('hides the view action from a role without the module permission', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    makeInvoice($lease);

    // `leasing` holds no invoices.* permission at all.
    $this->actingAs(makeUser('leasing', [$asset->id]));

    asTenant($asset, function () {
        // canViewAny already denies the whole resource — the strongest gate,
        // and the one that stops the list page being reachable in the first place.
        expect(InvoiceResource::canViewAny())->toBeFalse();
    });
});

it('gives the view modal no way to write', function () {
    $asset = makeAsset();
    $lease = makeLease(makeUnit($asset));
    $invoice = makeInvoice($lease, ['status' => 'issued']);

    $this->actingAs(makeUser('viewer', [$asset->id]));

    asTenant($asset, function () use ($invoice) {
        $table = Livewire::test(ListInvoices::class)
            ->instance()
            ->getTable();

        $view = collect($table->getRecordActions())
            ->first(fn ($a) => $a instanceof ViewAction);

        expect($view)->not->toBeNull();

        // No submit action on the modal — the structural reason a disabled
        // schema cannot be turned into an edit by a crafted request.
        $view->record($invoice);
        expect($view->getModalSubmitAction())->toBeNull();
    });
});
