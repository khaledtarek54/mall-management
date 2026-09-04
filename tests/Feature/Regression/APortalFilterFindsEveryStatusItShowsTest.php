<?php

/*
|--------------------------------------------------------------------------
| A portal filter can find every status it shows (SW-029)
|--------------------------------------------------------------------------
| The tenant portal's invoice and payment lists RENDER every status their columns can carry — each
| one has an arm in the column's own `formatStateUsing()` and a colour — and their status filters
| were hand-written `->only()` lists that could not name four of them each.
|
| Measured at HEAD 2026-09-04:
|   invoices  — offered issued/partially_paid/paid/overdue; `disputed`, `cancelled`, `credited` and
|               `written_off` were visible in the list and unreachable from the filter.
|   payments  — offered captured/reconciled/settled/failed/refunded; `initiated`, `authorized`,
|               `bounced` and `voided` were not offered. `voided` shipped on 2026-08-28 and no
|               filter has ever been able to name it.
|
| The third portal filter — credit notes — had the correct derivation, written out inline under a
| comment explaining exactly why a hand-written list is wrong. Two of its three neighbours never got
| it, so `App\Support\StatusOptions` is now that reasoning's one home and all three read it.
|
| Every refusal here is paired with a control that must succeed: a filter offering NOTHING would
| satisfy "does not offer draft" on its own, and a scope returning nothing would satisfy every
| "cannot see" assertion.
*/

use App\Filament\Portal\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Portal\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Portal\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
use App\Support\TenantVisibility;
use App\Support\ValueSets;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('portal')));
afterEach(function () {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app()->setLocale('en');
});

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'PSF']);
    $this->tenant = makeTenant(['name' => 'Cafe Crema']);
    $this->lease = makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);

    $this->actingAs(makeTenantUser($this->tenant), 'portal');
});

/**
 * The keys a list page's `status` filter actually offers, read off the built table.
 *
 * Named with the `portalStatusFilter` prefix because a file-scope helper name is GLOBAL across
 * tests/ and a collision exits the whole suite 255 with no output on either stream.
 */
function portalStatusFilterOptions(string $pageClass): array
{
    $filter = Livewire::test($pageClass)->instance()->getTable()->getFilters()['status'];

    return array_keys($filter->getOptions());
}

it('offers a tenant every invoice status they may be shown, and never draft', function () {
    // The four the `->only()` list could not name, plus one it could — so the assertion cannot pass
    // by the filter happening to be empty.
    foreach (['issued', 'disputed', 'cancelled', 'credited', 'written_off'] as $status) {
        makeInvoice($this->lease, ['status' => $status]);
    }

    $offered = portalStatusFilterOptions(ListInvoices::class);

    expect($offered)->toBe(TenantVisibility::visibleFor('invoices'))
        ->and($offered)->toHaveCount(8)
        // The four the row named. Spelled out rather than left to the derivation, because the
        // derivation is the thing under test.
        ->and($offered)->toContain('disputed', 'cancelled', 'credited', 'written_off')
        // The control on the other side: a portal filter must never offer `draft`. It would return
        // nothing by construction (the resource scopes `->visibleToTenant()`) and imply the tenant
        // has unissued documents to go and look at.
        ->and($offered)->not->toContain('draft');
});

it('finds a written-off invoice through the filter that could not name it', function () {
    $writtenOff = makeInvoice($this->lease, ['status' => 'written_off']);
    $issued = makeInvoice($this->lease, ['status' => 'issued']);

    // The membership assertion is load-bearing and NOT redundant: `SelectFilter::apply()` filters
    // on whatever value it is handed, so `filterTable()` selects correctly even for a status the
    // dropdown never offered. Driving the filter alone therefore passes with the fix reverted —
    // it proves the query, and the defect was the OPTION LIST.
    expect(portalStatusFilterOptions(ListInvoices::class))->toContain('written_off');

    // And then it must actually select. A tenant chasing a number they remember from a statement is
    // the reason `written_off` stays visible to them at all.
    Livewire::test(ListInvoices::class)
        ->assertOk()
        ->filterTable('status', 'written_off')
        ->assertCanSeeTableRecords([$writtenOff])
        ->assertCanNotSeeTableRecords([$issued]);
});

it('offers every payment status a receipt may hold', function () {
    // `payments` is in no TenantVisibility::HIDDEN entry — nothing about a receipt is withheld from
    // the party who made it — so the tenant's filter is the full accepted set.
    $offered = portalStatusFilterOptions(ListPayments::class);

    expect($offered)->toBe(ValueSets::allowed('payments', 'status'))
        ->and($offered)->toHaveCount(9)
        ->and($offered)->toContain('initiated', 'authorized', 'bounced', 'voided');
});

it('finds a voided receipt through the filter, without calling it refunded', function () {
    // `voided` and `refunded` are two different statements about the tenant's money (2026-08-28) and
    // this list is where a tenant reads which one happened.
    $voided = Payment::create([
        'tenant_id' => $this->tenant->id, 'payment_date' => '2026-03-01', 'amount' => 5000,
        'method' => 'cash', 'status' => 'voided', 'currency' => 'EGP',
    ]);
    $captured = Payment::create([
        'tenant_id' => $this->tenant->id, 'payment_date' => '2026-03-02', 'amount' => 7000,
        'method' => 'cash', 'status' => 'captured', 'currency' => 'EGP',
    ]);

    // Offered first, for the reason spelled out in the invoice test above.
    expect(portalStatusFilterOptions(ListPayments::class))->toContain('voided');

    Livewire::test(ListPayments::class)
        ->assertOk()
        ->filterTable('status', 'voided')
        ->assertCanSeeTableRecords([$voided])
        ->assertCanNotSeeTableRecords([$captured]);
});

it('leaves the credit-note filter exactly as it was — the one that already had it right', function () {
    // The extraction's control. This filter derived correctly before the seam existed, so if moving
    // it into StatusOptions changed ANY of its three options, the seam is not the same rule.
    expect(portalStatusFilterOptions(ListCreditNotes::class))
        ->toBe(['issued', 'applied', 'void'])
        ->not->toContain('draft');
});

it('labels every offered status in the reader’s own language', function () {
    // The half no gate sees: ArabicPanelHasNoEnglishChromeConformanceTest sweeps a filter's LABEL
    // and never its OPTIONS, so a status with no Arabic key would print English inside the Arabic
    // panel and nothing would go red. Both money surfaces, both filters, every value.
    app()->setLocale('ar');

    $labels = array_merge(
        Livewire::test(ListInvoices::class)->instance()->getTable()->getFilters()['status']->getOptions(),
        Livewire::test(ListPayments::class)->instance()->getTable()->getFilters()['status']->getOptions(),
    );

    // 8 tenant-visible invoice statuses + 9 payment statuses, no key shared between them.
    expect($labels)->toHaveCount(17);

    // Collect the offenders rather than asserting per label: a Pest matcher takes no message
    // argument, so a per-label assertion would fail without naming the status that failed.
    $withoutArabic = array_keys(array_filter(
        $labels,
        fn (string $label): bool => ! preg_match('/\p{Arabic}/u', $label),
    ));

    expect($withoutArabic)->toBe([]);
});
