<?php

/*
|--------------------------------------------------------------------------
| A FROZEN module is absent, not merely switched off (2026-08-22)
|--------------------------------------------------------------------------
| `modules.eta` had been false by default since 2026-07-03, and a settings migration turned it off
| for existing installs. ETA was still all over the running system anyway:
|
|   - an "ETA e-Invoicing" TAB on /admin/settings, with two `->required()` fields an operator had to
|     fill in for an integration that has never been certified — and which nothing read;
|   - a MODULES TOGGLE inviting them to switch it on;
|   - an "ETA Status" COLUMN on every invoice list, the one ETA surface that was never module-gated;
|   - "Submit invoices to the Egyptian Tax Authority" as a grantable right on the ROLES matrix;
|   - an ETA reference block on the invoice PDF — printing a MOCK submission id on the document a
|     tenant files with their own accountant;
|   - three `eta_*` keys in the mobile API invoice payload;
|   - ~55 seeded "Valid" badges in the demo data, which is what makes a frozen module read as a
|     finished one.
|
| So "off" and "unfinished" looked identical, and the difference was presented as the operator's to
| decide. `App\Support\Modules::FROZEN` makes it the code's: `enabled()` answers false before the
| settings row is consulted, so a stale row, a restored backup or a hand-edited `settings` table
| cannot bring an uncertified tax-authority integration back in front of anyone.
|
| Every refusal below is paired with a CONTROL that must still succeed — a gate that hid everything
| would satisfy the refusals on its own and read as a pass.
*/

use App\Filament\Admin\Pages\Settings as SettingsPage;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Widgets\EtaCompliance;
use App\Http\Resources\Api\V1\InvoiceResource;
use App\Services\InvoicePdfService;
use App\Settings\ModulesSettings;
use App\Support\Modules;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

/**
 * Turn the module ON at the settings row.
 *
 * Every surface assertion below runs with this applied, deliberately. `modules.eta` has defaulted
 * to false since 2026-07-03, so a test that leaves it alone passes for the OLD reason and would go
 * on passing with the freeze deleted — it would be measuring the default, not the gate. Flipping
 * the row first makes each refusal a statement about `Modules::FROZEN` and nothing else.
 */
function askForEta(): void
{
    $settings = app(ModulesSettings::class);
    $settings->eta = true;
    $settings->save();
}

it('answers false for a frozen module even when the settings row says true', function () {
    // The whole point of freezing in CODE. Someone restoring an older database, or an operator who
    // turned it on before the freeze, must not get the module back.
    $settings = app(ModulesSettings::class);
    $settings->eta = true;
    $settings->save();

    expect(Modules::enabled('eta'))->toBeFalse()
        ->and(Modules::frozen('eta'))->toBeTrue();

    // CONTROL: an ordinary module still obeys its row in both directions, so the gate is a freeze
    // and not a blanket "everything is off".
    $settings->cam = false;
    $settings->save();
    expect(Modules::enabled('cam'))->toBeFalse();

    $settings->cam = true;
    $settings->save();
    expect(Modules::enabled('cam'))->toBeTrue();
});

it('keeps the frozen key inside Modules::KEYS', function () {
    // A key OUTSIDE KEYS is a guard that can never refuse — `enabled()` returns true for anything
    // unlisted. Dropping `eta` from KEYS would silently turn every `Modules::enabled('eta')` call
    // site into a permanent yes, which is the exact opposite of freezing and errors nowhere.
    expect(Modules::KEYS)->toContain('eta')
        ->and(Modules::toggleable())->not->toContain('eta')
        ->and(Modules::toggleable())->toContain('cam');
});

it('states a reviewable reason for every frozen module', function () {
    foreach (Modules::FROZEN as $key => $reason) {
        // A frozen key that is not a real module key freezes nothing — `enabled()` would never
        // consult FROZEN for a name no call site passes, and the entry would read as coverage.
        expect(in_array($key, Modules::KEYS, true))
            ->toBeTrue("{$key} is frozen but is not a real module key");
        expect(mb_strlen($reason))->toBeGreaterThan(60, "[{$key}] is frozen with a reason too thin to review");
    }
});

it('offers no toggle and no settings tab for a frozen module', function () {
    askForEta();
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    $page = new SettingsPage;
    $page->mount();

    // `withHidden: true` on purpose: a field that merely renders hidden is still a field, and the
    // claim here is that it does not exist at all.
    $paths = collect($page->form->getFlatComponents(withHidden: true))
        ->map(fn ($component) => method_exists($component, 'getStatePath') ? $component->getStatePath() : null)
        ->filter()
        ->map(fn (string $path) => str_replace('data.', '', $path))
        ->values();

    expect($paths)->not->toContain('modules.eta')
        ->and($paths->filter(fn (string $p) => str_starts_with($p, 'eta.'))->all())->toBe([])
        // CONTROL: the modules section is still rendered, so the absence above is targeted.
        ->and($paths)->toContain('modules.cam');
});

it('has retired the ETA submit permission from the catalogue', function () {
    // The roles matrix renders one checkbox per catalogue row, so a permission left behind keeps
    // advertising the module on the screen an operator uses to decide what a role may do.
    $this->seed(RolesPermissionsSeeder::class);

    expect(Permission::where('name', 'invoices.submit_to_eta')->exists())->toBeFalse()
        // CONTROL: the rest of the invoices module is untouched.
        ->and(Permission::where('name', 'invoices.run_monthly_billing')->exists())->toBeTrue();
});

it('renders no ETA column, filter or action on the invoices table', function () {
    askForEta();
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $asset = makeAsset();
    $invoice = makeInvoice(makeLease(makeUnit($asset), makeTenant()), [
        'status' => 'issued', 'total' => 1000, 'balance' => 1000,
    ]);

    $this->actingAs(makeUser('super_admin', [$asset->id]));

    asTenant($asset, function () use ($invoice) {
        $table = Livewire::test(ListInvoices::class)->instance()->getTable();

        $columns = array_keys($table->getColumns());
        $filters = array_keys($table->getFilters());
        $actions = collect($table->getActions())->map(fn ($a) => $a->getName())->all();

        // A column is "there" while it exists but is hidden — Filament keeps it in the schema — so
        // visibility is the question, not membership.
        expect($table->getColumn('eta_status')?->isVisible() ?? false)->toBeFalse()
            ->and(in_array('eta_status', $filters, true) && $table->getFilter('eta_status')->isVisible())->toBeFalse()
            ->and(in_array('needs_eta_attention', $filters, true) && $table->getFilter('needs_eta_attention')->isVisible())->toBeFalse()
            ->and(in_array('eta_pending', $filters, true) && $table->getFilter('eta_pending')->isVisible())->toBeFalse()
            // CONTROL: the table is real and still shows its ordinary columns and filters.
            ->and($columns)->toContain('number')
            ->and($filters)->toContain('status');

        // The row action is gated in BOTH visible() and action(); this asserts the UI half, and the
        // 403 half is `abort_unless(… && Modules::enabled('eta'))` in the action body.
        if (in_array('submitToEta', $actions, true)) {
            expect($table->getAction('submitToEta')->record($invoice)->isVisible())->toBeFalse();
        }
    });
});

it('hides the ETA compliance widget from the dashboard it is registered on', function () {
    askForEta();
    // Registered on the accounting layout and left there deliberately: it comes back with the
    // module rather than needing to be re-wired. `canView()` is what keeps it off the page.
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('accounting'));

    expect(EtaCompliance::canView())->toBeFalse();
});

it('omits the eta_* keys from the mobile API invoice payload', function () {
    askForEta();
    ensureAllPropertiesAsset();

    $asset = makeAsset();
    $invoice = makeInvoice(makeLease(makeUnit($asset), makeTenant()), [
        'status' => 'issued', 'total' => 1000, 'balance' => 1000,
        'eta_status' => 'valid', 'eta_submission_id' => 'SUB-1', 'eta_long_id' => 'LONG-1',
    ]);

    // Written straight onto the row so the assertion is about the RESOURCE, not about the module
    // being unable to produce the data.
    $payload = (new InvoiceResource($invoice->fresh()))->toArray(Request::create('/api/v1/invoices'));

    expect($payload)->not->toHaveKey('eta_status')
        ->not->toHaveKey('eta_submission_id')
        ->not->toHaveKey('eta_long_id')
        // CONTROL: the payload is otherwise intact — the spread did not eat the rest of the array.
        ->toHaveKey('number')
        ->toHaveKey('balance');
});

it('keeps the ETA reference block off the invoice PDF', function () {
    askForEta();
    ensureAllPropertiesAsset();

    $asset = makeAsset();
    $invoice = makeInvoice(makeLease(makeUnit($asset), makeTenant()), [
        'status' => 'issued', 'total' => 1000, 'balance' => 1000,
        'eta_submission_id' => 'SUB-VISIBLE-IF-BROKEN', 'eta_long_id' => 'LONG-VISIBLE-IF-BROKEN',
    ]);

    // Through the service's own view data, not a hand-built array: a local copy would reproduce
    // the service's bugs faithfully instead of catching them (see InvoicePdfService::viewData()).
    $html = view('invoices.pdf', app(InvoicePdfService::class)->viewData($invoice->fresh()))->render();

    expect($html)->not->toContain('SUB-VISIBLE-IF-BROKEN')
        ->not->toContain('LONG-VISIBLE-IF-BROKEN')
        // CONTROL: the PDF really did render — an exception or an empty string would pass above.
        ->toContain($invoice->number);
});
