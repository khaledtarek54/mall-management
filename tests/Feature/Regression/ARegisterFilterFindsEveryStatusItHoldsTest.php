<?php

/*
|--------------------------------------------------------------------------
| An admin register's status filter can find every status the register holds (SW-027)
|--------------------------------------------------------------------------
| `App\Support\StatusOptions` shipped with SW-029 and its `for()` method states its own purpose:
| "the OPERATOR's half — an admin list shows every row the column can carry, so its filter has to be
| able to find every one of them". Three admin registers were still hand-kept `->only()` /
| `->except()` lists, and `docs/modules/03-tenant-portal-users.md` already named them and left them
| to this row.
|
| Measured at HEAD ca59741d (2026-09-05), each table class read against `ValueSets::allowed()` — the
| set the wildcard `eloquent.saving` listener enforces, i.e. exactly what the column can hold:
|
|   payments  — `PaymentsTable:96` offered 4 of 9. `initiated`, `authorized`, `settled`, `bounced`
|               and `voided` were unreachable. `voided` shipped 2026-08-28 to say money was NOT
|               returned; it is in no worklist tab either, so nothing on the register could name it.
|   invoices  — `InvoicesTable:141` offered 5 of 9. `disputed`, `cancelled`, `credited` and
|               `written_off` were unreachable — each one rendered in colour by the `status` column
|               a few lines above the filter.
|   leases    — `LeasesTable:223` offered 6 of 7, dropping `cancelled`. That exclusion arrived in
|               `bcca5b17` (May 2026) with no comment and no reason in the commit message, and
|               `cancelled` is a status the lease FORM offers, so an operator can save it and then
|               not find it.
|
| A TAB set is a curated worklist and legitimately not exhaustive; the FILTER is the exhaustive
| tool, which is why the fix belongs on the filter and the tabs are untouched.
|
| The testing trap inherited from the portal twin: `SelectFilter::apply()` filters on whatever value
| it is handed, so `filterTable('status', 'voided')` selects correctly even with the fix reverted.
| Membership in the OPTION LIST is the defect, so it is asserted first and separately. And every
| "this is now offered" assertion is paired with "the ones it always offered are still there", or a
| derivation that returned an empty array would read as a pass.
*/

use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\Payments\Pages\ListPayments;
use App\Models\Payment;
use App\Support\ValueSets;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->asset = makeAsset(['code' => 'RSF']);
    $this->lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);

    $this->actingAs(makeUser('super_admin', [$this->asset->id]));
});

afterEach(fn () => app()->setLocale('en'));

/**
 * The keys an admin list page's `status` filter actually offers, read off the BUILT table — the
 * same access path `Tests\Support\FilterSweep` uses (`instance()->getTable()->getFilters()`).
 *
 * Named with the `adminStatusFilter` prefix because a file-scope helper name is GLOBAL across
 * tests/ and a collision exits the whole suite 255 with no output on either stream; the portal twin
 * owns `portalStatusFilterOptions`.
 *
 * @return array<int, string>
 */
function adminStatusFilterOptions(string $pageClass): array
{
    return array_keys(
        Livewire::test($pageClass)->instance()->getTable()->getFilters()['status']->getOptions()
    );
}

it('offers every payment status a receipt can hold', function () {
    $offered = asTenant($this->asset, fn () => adminStatusFilterOptions(ListPayments::class));

    expect($offered)->toBe(ValueSets::allowed('payments', 'status'))
        ->and($offered)->toHaveCount(9)
        // The five the `->only()` list could not name. Spelled out rather than left to the
        // derivation, because the derivation is the thing under test.
        ->and($offered)->toContain('initiated', 'authorized', 'settled', 'bounced', 'voided')
        // The control: the four it DID offer must still be there. An empty options array would
        // satisfy nothing above it, but a derivation that lost the common statuses would be a
        // worse register than the one this replaces.
        ->and($offered)->toContain('captured', 'reconciled', 'failed', 'refunded');
});

it('offers every invoice status the register can hold', function () {
    $offered = asTenant($this->asset, fn () => adminStatusFilterOptions(ListInvoices::class));

    expect($offered)->toBe(ValueSets::allowed('invoices', 'status'))
        ->and($offered)->toHaveCount(9)
        ->and($offered)->toContain('disputed', 'cancelled', 'credited', 'written_off')
        ->and($offered)->toContain('draft', 'issued', 'partially_paid', 'paid', 'overdue');
});

it('offers every lease status the register can hold, cancelled included', function () {
    $offered = asTenant($this->asset, fn () => adminStatusFilterOptions(ListLeases::class));

    expect($offered)->toBe(ValueSets::allowed('leases', 'status'))
        ->and($offered)->toHaveCount(7)
        // The one the `->except()` dropped — savable through the lease form, and therefore
        // findable from the register.
        ->and($offered)->toContain('cancelled')
        ->and($offered)->toContain('draft', 'pending_approval', 'active', 'expired', 'renewed', 'terminated');
});

it('finds a written-off invoice through the filter that could not name it', function () {
    $writtenOff = makeInvoice($this->lease, ['status' => 'written_off']);
    $issued = makeInvoice($this->lease, ['status' => 'issued']);

    // The membership assertion is load-bearing and NOT redundant: `SelectFilter::apply()` filters
    // on whatever value it is handed, so the selection below passes with the fix reverted. It
    // proves the QUERY; the defect was the OPTION LIST.
    expect(asTenant($this->asset, fn () => adminStatusFilterOptions(ListInvoices::class)))
        ->toContain('written_off');

    asTenant($this->asset, fn () => Livewire::test(ListInvoices::class)
        ->assertOk()
        ->filterTable('status', 'written_off')
        ->assertCanSeeTableRecords([$writtenOff])
        ->assertCanNotSeeTableRecords([$issued]));
});

it('finds a voided receipt through the filter, without calling it refunded', function () {
    // `voided` and `refunded` are two different statements about the money (2026-08-28), and the
    // payments register is where an operator separates a keying error from a real refund.
    $invoice = makeInvoice($this->lease);

    $voided = Payment::create([
        'tenant_id' => $this->lease->tenant_id, 'payment_date' => '2026-03-01', 'amount' => 5000,
        'method' => 'cash', 'status' => 'voided', 'currency' => 'EGP',
    ]);
    $captured = Payment::create([
        'tenant_id' => $this->lease->tenant_id, 'payment_date' => '2026-03-02', 'amount' => 7000,
        'method' => 'cash', 'status' => 'captured', 'currency' => 'EGP',
    ]);

    // `Payment` is `#[PropertyOwned(via: 'invoices')]`, so a receipt with no invoice behind it is
    // outside the selected property and the register would not list it whatever the filter said.
    // Zero allocation: the link is what gives the receipt a property, not a settlement.
    $voided->invoices()->attach($invoice->id, ['allocated_amount' => 0]);
    $captured->invoices()->attach($invoice->id, ['allocated_amount' => 0]);

    expect(asTenant($this->asset, fn () => adminStatusFilterOptions(ListPayments::class)))
        ->toContain('voided');

    asTenant($this->asset, fn () => Livewire::test(ListPayments::class)
        ->assertOk()
        ->filterTable('status', 'voided')
        ->assertCanSeeTableRecords([$voided])
        ->assertCanNotSeeTableRecords([$captured]));
});

it('labels every newly offered status in the reader’s own language', function () {
    // The half no gate sees: ArabicPanelHasNoEnglishChromeConformanceTest sweeps a filter's LABEL
    // and never its OPTIONS, so widening a filter is exactly the change that can put an English
    // word inside the Arabic panel with nothing going red.
    app()->setLocale('ar');

    $labels = [];

    foreach ([[ListInvoices::class, 'invoices'], [ListPayments::class, 'payments'], [ListLeases::class, 'leases']] as [$page, $table]) {
        $options = asTenant(
            $this->asset,
            fn () => Livewire::test($page)->instance()->getTable()->getFilters()['status']->getOptions(),
        );

        foreach ($options as $value => $label) {
            // Keyed by table AND value: `draft` and `cancelled` are shared between the invoice and
            // lease groups, so a flat merge would silently drop rows and the count would lie.
            $labels["{$table}.{$value}"] = $label;
        }
    }

    // 9 invoice + 9 payment + 7 lease = 25. Asserted so the sweep cannot report on a set it failed
    // to collect.
    expect($labels)->toHaveCount(25);

    // Collect the offenders rather than asserting per label: a Pest matcher takes no message
    // argument, so a per-label assertion would fail without naming the status that failed.
    $withoutArabic = array_keys(array_filter(
        $labels,
        fn (string $label): bool => ! preg_match('/\p{Arabic}/u', $label),
    ));

    expect($withoutArabic)->toBe([]);
});
