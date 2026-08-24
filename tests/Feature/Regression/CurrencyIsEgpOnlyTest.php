<?php

/*
|--------------------------------------------------------------------------
| A currency column may carry only the currency this system can honour
|--------------------------------------------------------------------------
| Fifteen tables carry a `currency` column defaulting to 'EGP', and for the whole life of the system
| nothing read one: there is no exchange-rate table, no rate stamped on any document, and no currency
| or base-amount column on `journal_lines`. The schema therefore *looked* like multi-currency support
| and granted none — the same class of problem CLAUDE.md records for the 31 `{module}.delete`
| permissions that read as rights and grant nothing.
|
| One screen made that reachable. The vendor-contract form offered a six-option currency picker one
| line below an amount field prefixed `EGP`, and the contract's value feeds the SLA-penalty basis
| (`AssessSlaPenaltyService`), which posts. So picking a foreign code put a foreign number into an
| EGP ledger at 1:1 — silently, with every downstream total still balancing.
|
| The picker is gone and the set is enforced. Two properties, each paired with a control, because a
| refusal test passes just as happily against a model that rejects everything:
|
|   1. The model refuses a currency the system cannot honour — on both tables that could reach one.
|   2. The vendor-contract form no longer offers the field at all, so the guard is never the first
|      thing an operator meets.
|
| The rule the two screens follow: **a currency field survives only where the value is PRINTED.** The
| asset's is (it leads the owner statement) so it stays, visible and read-only; the vendor contract's
| was not, so it went. When FX actually exists (EG-31 in docs/EGYPT-MARKET-FIT.md) this test is what
| should be deleted, deliberately, rather than quietly widened.
*/

use App\Models\Vendor;
use App\Models\VendorContract;
use App\Support\ValueSets;

it('refuses a currency the ledger cannot honour, on every table that can carry one', function () {
    $asset = makeAsset();

    // Controls first. Both tables must accept the one currency the system can post.
    expect($asset->fresh()->currency)->toBe('EGP');

    $vendor = Vendor::create([
        'name' => 'Nile Facilities', 'type' => 'contractor', 'status' => 'active',
    ]);

    $contract = VendorContract::create([
        'vendor_id' => $vendor->id, 'asset_id' => $asset->id, 'name' => 'Annual cleaning',
        'status' => 'draft', 'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'value' => 120000, 'currency' => 'EGP',
    ]);

    expect($contract->fresh()->currency)->toBe('EGP');

    // The refusals. Each would otherwise post a foreign number to an EGP ledger at 1:1.
    expect(fn () => $contract->update(['currency' => 'USD']))->toThrow(DomainException::class);
    expect(fn () => $asset->update(['currency' => 'USD']))->toThrow(DomainException::class);

    expect($contract->fresh()->currency)->toBe('EGP')
        ->and($asset->fresh()->currency)->toBe('EGP');
});

it('does not offer a currency the guard would refuse', function () {
    // The other half, and the reason this is not just a model test: until 2026-08-20 the picker
    // offered five foreign codes, so the guard would have been the operator's first experience of
    // the rule — a refusal toast on a value the screen had invited them to choose.
    //
    // Asserted against this ONE file's source, deliberately. The first cut of this case drove the
    // real Livewire form and used `assertTableActionDataSet()` with a closure — which passed just
    // as happily when the predicate was inverted, i.e. it asserted nothing at all. A repo-wide
    // source scan would be the wrong answer (slow, and false-positive prone); one file is neither.
    $source = file_get_contents(
        base_path('app/Filament/Admin/Resources/Vendors/RelationManagers/ContractsRelationManager.php')
    );

    // Narrowed to the FORM. The same class holds the table, and a `TextColumn::make('currency')`
    // there would be perfectly legitimate — showing what a contract is denominated in is not the
    // same as inviting somebody to change it.
    $form = substr($source, $start = strpos($source, 'public function form('),
        strpos($source, 'public function table(') - $start);

    // The control: the form still declares the field the currency used to sit beside, so a renamed
    // method or a moved file cannot satisfy this by making both needles absent.
    expect($form)->toContain("TextInput::make('value')")
        ->and($form)->not->toContain("make('currency')");
});

it('keeps the set to exactly what the ledger can post', function () {
    // Widening this set is a decision about FX, not a typo fix: it needs a rate table, a rate on
    // every originating document, and a base-amount column on `journal_lines`.
    expect(ValueSets::allowed('assets', 'currency'))->toBe(['EGP'])
        ->and(ValueSets::allowed('vendor_contracts', 'currency'))->toBe(['EGP']);
});
