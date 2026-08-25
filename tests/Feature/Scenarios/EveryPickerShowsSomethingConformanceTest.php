<?php

/*
|--------------------------------------------------------------------------
| Every picker in the panel shows what it has (2026-08-25)
|--------------------------------------------------------------------------
| Asked for directly: "go through every single dropdown in the system and make sure the suggestion
| is working fine." A per-screen answer would be stale the week after, so this is the sweep, run on
| every admin Create form with a property selected and demo data behind it.
|
| The property it enforces: **a picker whose scoped table has rows must offer rows.** That is what
| an operator means by "the suggestions work", and it is what the panel did not do — ~85 of 111
| record pickers handed Filament a static empty array and waited to be typed into, which renders
| identically to "no such record".
|
| A picker over a genuinely empty table is EXEMPT rather than failed — the answer there is a real
| empty-state message, which is a different fix from the one this gate is about, and the message is
| tested beside it. A picker narrowed by a parent field that nothing has chosen yet is exempt for
| the same reason: `whereRaw('1 = 0')` until a tenant is picked is correct behaviour.
|
| Following the rule this suite has learned three times: **the sweep asserts it found something
| first.** A discovery that quietly matched zero pickers would pass for ever while covering nothing.
*/

use App\Support\Filament\EntitySelect;

/**
 * Pickers whose query is deliberately `whereRaw('1 = 0')` until a PARENT field is chosen.
 *
 * Offering nothing is correct here and the operator sees it the moment they pick the tenant or the
 * vendor. Listed rather than detected, because "the query returned nothing" cannot tell the
 * difference between waiting for a parent and being broken — which is the distinction this whole
 * gate exists to draw.
 */
const DEPENDS_ON_A_PARENT = [
    'CreateCreditNote → invoice_id',
    'CreatePayment → invoice_id',
    'CreatePostDatedCheque → invoice_id',
    'CreateVendorBill → vendor_contract_id',
    'CreateVendorBill → purchase_request_id',
    'CreateRecurringExpense → vendor_contract_id',
    'CreateTenantRequest → unit_id',
    'CreateCamExpensePool → ledgerAccounts',
    // Narrowed to the UNIT chosen above it, and `visible()` until that unit actually has an
    // ownership — so the operator never meets it empty. The sweep reads hidden components on
    // purpose (a picker that is broken only when shown is still broken), which is why this one has
    // to be named rather than detected.
    'CreateLease → unit_ownership_id',
];
use App\Models\Asset;
use App\Models\BankAccount;
use App\Support\Search\OptionDisplay;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\DemoSeeder;
use Database\Seeders\DepartmentSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Database\Seeders\UtilityTariffSeeder;
use Filament\Facades\Filament;
use Filament\Resources\Pages\CreateRecord;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Schema;
use Livewire\Livewire;

beforeEach(function () {
    // The REFERENCE data a real install has, which `DemoSeeder` alone does not lay down. Without
    // it the departments, chart and tariff tables are empty, every picker over them is legitimately
    // empty, and the gate reports on a set it cannot see — it stayed GREEN with the portfolio-row
    // fix deleted, which is the failure mode this suite has shipped three times.
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(DepartmentSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(UtilityTariffSeeder::class);
    $this->seed(DemoSeeder::class);

    $this->asset = Asset::where('code', 'AW')->firstOrFail();
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/**
 * Every EntitySelect anywhere inside a built schema, however deeply nested.
 *
 * Takes a Schema OR a Component, because the top level is a Schema and everything under it is a
 * Component — walking only one of the two shapes finds the fields at the root and none in a
 * Section, Tabs or Repeater, which is where most of them live.
 */
function pickersIn(Schema|Component $node): array
{
    $found = [];

    $containers = $node instanceof Schema
        ? [$node]
        : $node->getChildSchemas(withHidden: true);

    foreach ($containers as $container) {
        foreach ($container->getComponents(withHidden: true) as $child) {
            if ($child instanceof EntitySelect) {
                $found[] = $child;
            }

            $found = array_merge($found, pickersIn($child));
        }
    }

    return $found;
}

it('offers rows on every picker whose table has rows', function () {
    $pages = [];

    foreach (Filament::getPanel('admin')->getResources() as $resource) {
        foreach ($resource::getPages() as $registration) {
            $page = $registration->getPage();

            if (is_subclass_of($page, CreateRecord::class)) {
                $pages[$page] = $page;
            }
        }
    }

    $checked = 0;
    $empty = [];

    foreach ($pages as $page) {
        try {
            $component = Livewire::test($page);
            $schema = $component->instance()->getSchema('form');
        } catch (Throwable) {
            // Rendering is ResourceFormSmokeTest's question, not this one.
            continue;
        }

        if ($schema === null) {
            continue;
        }

        foreach (pickersIn($schema) as $picker) {
            $checked++;

            try {
                $options = $picker->getOptions() ?? [];
            } catch (Throwable) {
                continue;
            }

            if ($options !== []) {
                continue;
            }

            // A `->suggest()` picker narrows what it SHOWS on purpose and search still reaches
            // every row — "nothing to suggest until you pick the meter's type" is correct, and it
            // says so with the search prompt rather than an empty-state. Derived from the picker
            // itself, never from a list of screen names, so a new suggest call site is exempt by
            // being one.
            if ($picker->suggestsASubset()) {
                continue;
            }

            // Empty is only a DEFECT when there was something to show. The two cases look identical
            // in the panel — which is the whole reason this class of bug survives — so they are
            // told apart here by asking the model's own table.
            //
            // Unscoped on purpose: the question is "does this table hold anything at all", and
            // asking it through the scope would let the exact bug this gate exists to catch answer
            // "nothing to show" on its own behalf.
            $model = $picker->getEntityModel();
            $rows = $model !== null ? $model::query()->count() : 0;

            $empty[] = [
                'where' => class_basename($page).' → '.$picker->getName(),
                'rows' => $rows,
                'model' => $model !== null ? class_basename($model) : '?',
            ];
        }
    }

    // The sweep must have found pickers before it can report on them. A discovery that quietly
    // matched zero would pass for ever while covering nothing — this project has shipped that gate.
    expect($checked)->toBeGreaterThan(40);

    // THE DEFECT: a picker offering nothing over a table that holds rows. Either the scope is
    // dropping them (departments: 5 portfolio-wide rows, 0 offered, on four screens) or the call
    // site narrowed to nothing.
    $broken = array_values(array_filter(
        $empty,
        fn (array $e) => $e['rows'] > 0 && ! in_array($e['where'], DEPENDS_ON_A_PARENT, true),
    ));

    expect($broken)->toBe([], 'Pickers offering nothing over a table that has rows: '
        .implode(' · ', array_map(fn (array $e) => "{$e['where']} ({$e['model']}: {$e['rows']} rows)", $broken)));
})->group('sweep');

it('says what is true when there is nothing to show, in both languages', function () {
    // The other half, and the half my first attempt got wrong: an empty picker used to render the
    // SEARCH PROMPT, which after the auto-browse change is false ("type to search" when searching
    // cannot help) and is the same string as the search box's placeholder — so the dropdown showed
    // two identical inputs stacked on each other. Reported from the panel on Bank Account.
    $en = OptionDisplay::emptyMessage(BankAccount::class);
    $prompt = OptionDisplay::searchPrompt(BankAccount::class);

    expect($en)->not->toBe($prompt)
        ->and($en)->toContain('Bank Accounts');

    app()->setLocale('ar');
    $ar = OptionDisplay::emptyMessage(BankAccount::class);
    app()->setLocale('en');

    // Arabic, and actually Arabic — the label is definite and nominative, so any template that
    // governs it grammatically is wrong for half the catalogue.
    expect($ar)->not->toBe($en)
        ->and(preg_match('/\p{Arabic}/u', $ar))->toBe(1);
});
