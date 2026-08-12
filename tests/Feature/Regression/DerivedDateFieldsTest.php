<?php

/*
|--------------------------------------------------------------------------
| A computed field is filled in, editable, and never left disagreeing
|--------------------------------------------------------------------------
| `commencement_date`, `term_months` and `expiry_date` were three INDEPENDENT inputs on the lease
| form, so a lease could be saved as "36 months" spanning twelve. That is not cosmetic:
| `term_months` is logged on the lease, copied by renewal, and read by the option-exercise service,
| so the disagreement propagates into the next contract.
|
| The same shape elsewhere: the manual invoice's `due_date` was free, while every service that
| raises an invoice derives it from the lease's payment terms — so a hand-typed invoice aged on a
| different rule from a generated one, and AR ageing is the report the owner reads.
|
| The rule these tests hold down is the one Yardi and MRI follow: **derived, pre-filled, and still
| editable — and editing the derived side back-derives its partner** rather than contradicting it.
| A one-way derivation would be a trap: an operator who types a negotiated end date would be left
| with a term that silently disagrees with it.
*/

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Support\LeaseTerm;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->asset = makeAsset(['code' => 'DF']);

    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('states the term rule once, and both directions agree with it', function () {
    // The rule the services already applied in two places: the expiry is the LAST DAY of the term,
    // so a twelve-month lease from 1 January ends on 31 December. A day later would bill a
    // thirteenth month on the anniversary.
    expect(LeaseTerm::expiryFrom('2026-01-01', 12))->toBe('2026-12-31')
        ->and(LeaseTerm::monthsBetween('2026-01-01', '2026-12-31'))->toBe(12)
        // Round-trips across a month-length boundary, which is where a naive +365 days breaks.
        ->and(LeaseTerm::expiryFrom('2026-01-31', 1))->toBe('2026-02-27')
        ->and(LeaseTerm::monthsBetween('2026-01-31', '2026-02-27'))->toBe(1);
});

it('clamps a month end instead of overflowing past it', function () {
    // The defect centralising the rule exposed. `addMonths()` — which both services used — turns
    // "31 August plus six months" into "31 February", which Carbon resolves to 3 March, so the
    // lease expired on 2 MARCH instead of 27 February: three days outside the agreed term, billed,
    // on any lease commencing on a day its end month does not have.
    expect(LeaseTerm::expiryFrom('2026-08-31', 6))->toBe('2027-02-27')
        ->and(LeaseTerm::expiryFrom('2026-01-31', 1))->toBe('2026-02-27')
        // …and a term that lands in a month long enough for the day is unchanged, which is why
        // this was invisible for so long: it only bites on a short target month.
        ->and(LeaseTerm::expiryFrom('2026-01-31', 12))->toBe('2027-01-30')
        ->and(LeaseTerm::expiryFrom('2026-05-31', 3))->toBe('2026-08-30');
});

it('refuses to round a negotiated end date into a tidy term', function () {
    // Null, not 11. An expiry aligned to a financial year or another tenant's fit-out is a real
    // date, and answering "11 months" would restate the contract as something nobody agreed. The
    // form leaves the term alone, so the operator sees the two genuinely differ.
    expect(LeaseTerm::monthsBetween('2026-01-01', '2026-12-30'))->toBeNull()
        // …and a backwards or equal pair derives nothing, which is what leaves the `after()`
        // validation rule free to refuse it rather than the derivation silently "fixing" it.
        ->and(LeaseTerm::monthsBetween('2026-06-01', '2026-05-01'))->toBeNull()
        ->and(LeaseTerm::monthsBetween('2026-06-01', '2026-06-01'))->toBeNull();
});

it('fills the lease expiry in from the commencement and the term', function () {
    $unit = makeUnit($this->asset, ['code' => 'DF-01', 'status' => 'vacant']);

    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $unit->id,
            'tenant_id' => makeTenant()->id,
            'status' => 'active',
            'commencement_date' => '2026-06-01',
            'term_months' => 24,
            'base_rent_monthly' => 5000,
        ])
        ->assertFormSet(['expiry_date' => '2028-05-31']);
});

it('back-derives the term when the operator types an end date', function () {
    // The direction that stops the pair disagreeing, and the one a one-way derivation would miss.
    $unit = makeUnit($this->asset, ['code' => 'DF-02', 'status' => 'vacant']);

    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $unit->id,
            'tenant_id' => makeTenant()->id,
            'status' => 'active',
            'commencement_date' => '2026-06-01',
            'term_months' => 36,
            'base_rent_monthly' => 5000,
        ])
        ->assertFormSet(['expiry_date' => '2029-05-31'])
        // The operator negotiates a shorter lease and types the end date.
        ->set('data.expiry_date', '2027-05-31')
        ->assertFormSet(['term_months' => 12]);
});

it('leaves the term alone for an end date that is not a whole number of months', function () {
    $unit = makeUnit($this->asset, ['code' => 'DF-03', 'status' => 'vacant']);

    Livewire::test(CreateLease::class)
        ->fillForm([
            'unit_id' => $unit->id,
            'tenant_id' => makeTenant()->id,
            'status' => 'active',
            'commencement_date' => '2026-06-01',
            'term_months' => 36,
            'base_rent_monthly' => 5000,
        ])
        ->set('data.expiry_date', '2027-03-15')
        // Untouched — the bespoke date stands and the term is not quietly restated.
        ->assertFormSet(['term_months' => 36, 'expiry_date' => '2027-03-15']);
});

it('derives a manual invoice\'s due date from the lease\'s own payment terms', function () {
    // The rule every billing service already applies. A hand-typed invoice used to age on whatever
    // the operator picked, so the same tenant could appear in two different AR buckets depending on
    // which path raised the invoice.
    $lease = makeLease(makeUnit($this->asset, ['code' => 'DF-04']), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'payment_terms_days' => 21,
    ]);

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'draft',
            'issue_date' => '2026-03-01',
        ])
        ->assertFormSet(['due_date' => '2026-03-22']);
});

it('still lets the operator override a derived due date', function () {
    // Derived is a starting point, not a lock. A supplier-style arrangement or a one-off extension
    // is the operator's call, and the field stays theirs.
    $lease = makeLease(makeUnit($this->asset, ['code' => 'DF-05']), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
        'payment_terms_days' => 7,
    ]);

    Livewire::test(CreateInvoice::class)
        ->fillForm([
            'lease_id' => $lease->id,
            'tenant_id' => $lease->tenant_id,
            'status' => 'draft',
            'issue_date' => '2026-03-01',
        ])
        ->assertFormSet(['due_date' => '2026-03-08'])
        ->set('data.due_date', '2026-04-30')
        ->assertFormSet(['due_date' => '2026-04-30']);
});
