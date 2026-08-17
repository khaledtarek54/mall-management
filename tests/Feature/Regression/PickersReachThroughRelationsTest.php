<?php

use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Support\Search\OptionDisplay;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * **A picker finds a record by what the operator knows about it — including what lives on a
 * RELATED record.**
 *
 * THE GAP. `HasSearchText`'s one invariant is that a blob is a pure function of the row's OWN
 * attributes (reach through a relation and renaming a tenant strands every blob quoting the old
 * name). So a lease's blob holds `LSE-AW-2026-0001` and nothing else — and typing a tenant's name
 * into the LEASE picker found nothing, while typing it into the top search bar found the lease
 * immediately, because `LeaseResource` declares `['search_text', 'tenant.search_text',
 * 'unit.search_text']`.
 *
 * Two surfaces, one question, two answers. The resources were right; the pickers never read them.
 * `OptionDisplay::searchRelations()` now DERIVES the paths from that declaration rather than
 * carrying a second list, so the two cannot disagree again.
 *
 * It also covers the invoice DEBTOR, which is derived from the lease on create rather than trusted
 * from the payload — see the two cases at the end, and the correction recorded there.
 *
 * THE HAZARD THIS FILE EXISTS FOR. Adding an OR to a scoped query is exactly how a property leak
 * gets written: `(scope AND ownBlob) OR relationBlob` binds AND-before-OR and the OR branch escapes
 * isolation entirely. The same trap is already recorded for table search in `TableSearchTest`. The
 * grouping test below is the one that must never be deleted.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();

    $this->here = makeAsset(['code' => 'HERE']);
    $this->elsewhere = makeAsset(['code' => 'ELSE']);

    $this->tenant = makeTenant([
        'name' => 'Cilantro Cafes',
        'phone' => '+20 100 555 7788',
        'tax_id' => '944112233',
    ]);
    $this->lease = makeLease(makeUnit($this->here, ['code' => 'A-04']), $this->tenant);

    // The SAME tenant, leasing in a property this user cannot see. Its lease must never surface.
    $this->foreignLease = makeLease(makeUnit($this->elsewhere, ['code' => 'Z-99']), $this->tenant);

    $this->actingAs(makeUser('manager', [$this->here->id]));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->here);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** @return array<int, int> the lease ids a picker would offer for this query */
function leasePickerIds(string $query): array
{
    return array_map('intval', array_keys(OptionDisplay::search(Lease::class, $query)));
}

it('finds a lease by everything the operator knows about its tenant', function () {
    // The reported complaint, in the four spellings an operator actually types.
    expect(leasePickerIds('cilantro'))->toContain($this->lease->id)
        // The phone as it is read off a screen — unbroken digits, where the stored value has
        // spaces and a `+`.
        ->and(leasePickerIds('01005557788'))->toContain($this->lease->id)
        ->and(leasePickerIds('944112233'))->toContain($this->lease->id)
        ->and(leasePickerIds($this->tenant->code))->toContain($this->lease->id)
        // …the unit it occupies…
        ->and(leasePickerIds('A-04'))->toContain($this->lease->id)
        // …and its own reference, which must not have been traded away for the rest.
        ->and(leasePickerIds($this->lease->reference))->toContain($this->lease->id);
});

it('narrows across relations rather than widening', function () {
    // Words AND, sources OR: one word matches through the tenant and the other through the unit,
    // and only a lease matching BOTH survives. A naive OR-everything would return every lease of
    // that tenant AND every lease in that unit.
    expect(leasePickerIds('cilantro a-04'))->toContain($this->lease->id)
        ->and(leasePickerIds('cilantro z-99'))->not->toContain($this->lease->id);
});

it('keeps the relation search INSIDE the property scope', function () {
    // The one that must never be deleted. `(scope AND own) OR relation` binds AND-before-OR, and
    // the OR branch would escape isolation — a restricted user searching a tenant's name would be
    // offered that tenant's lease in a mall they cannot see. The grouping is what prevents it.
    //
    // Both leases belong to the SAME tenant, so a leak here is not hypothetical: the query that
    // finds one finds the other unless the scope holds.
    expect(leasePickerIds('cilantro'))
        ->toContain($this->lease->id)          // control — the search genuinely works…
        ->not->toContain($this->foreignLease->id); // …and still does not cross the property line.
});

it('derives the relation paths from what the resource already declares', function () {
    // Not a second list. If `LeaseResource::getGloballySearchableAttributes()` gains a path
    // tomorrow, the picker gains it too — which is the only version of this that stays true.
    expect(OptionDisplay::searchRelations(Lease::class))->toEqualCanonicalizing(['tenant', 'unit'])
        ->and(OptionDisplay::searchRelations(Invoice::class))
        ->toEqualCanonicalizing(['tenant', 'lease', 'lease.unit']);
});

it('finds an invoice by its tenant, not only by its number', function () {
    $invoice = makeInvoice($this->lease);

    expect(array_keys(OptionDisplay::search(Invoice::class, 'cilantro')))->toContain($invoice->id)
        ->and(array_keys(OptionDisplay::search(Invoice::class, $invoice->number)))->toContain($invoice->id);
});

it('derives the invoice debtor from the lease on create, rather than trusting the payload', function () {
    // The form shows the debtor read-only beside the lease picker — but a disabled field's value
    // still arrives in the Livewire payload, so a crafted request could name a different party and
    // bill someone who never agreed to the charge. The create page derives it instead of trusting
    // it.
    //
    // A DERIVATION, not a refusal, and that distinction was learned the hard way: an equality rule
    // on the model broke two deliberate behaviours the full suite caught —
    //   * `IssueInvoiceService` takes an explicit `$tenantId` so a violation fine, a bounced-cheque
    //     fee and a late fee carry the debtor stated on their SOURCE document; and
    //   * a DRAFT invoice may be freely re-homed to another lease before it is issued.
    // Both are documented decisions. The rule belongs on the one path where "the form never states
    // a debtor" is unambiguously true, not on the model where it is not.
    $other = makeTenant(['name' => 'Not The Lessee']);

    $page = Livewire::test(CreateInvoice::class)
        ->set('data.lease_id', $this->lease->id)
        // Tamper: name a party who is not the lease's.
        ->set('data.tenant_id', $other->id)
        ->set('data.status', 'draft')
        ->set('data.issue_date', '2026-03-01')
        ->set('data.due_date', '2026-03-15')
        ->set('data.period_start', '2026-03-01')
        ->set('data.period_end', '2026-03-31')
        ->set('data.items', [[
            'type' => 'base_rent', 'description' => 'Rent', 'amount' => 1000,
            'vat_rate' => 0, 'total' => 1000,
        ]])
        ->call('create');

    $page->assertHasNoFormErrors();

    $invoice = Invoice::query()->latest('id')->first();

    expect((int) $invoice->tenant_id)->toBe((int) $this->tenant->id)
        ->and((int) $invoice->tenant_id)->not->toBe((int) $other->id);
});

it('still lets a service state the debtor from a source document', function () {
    // The control for the rule above, and the reason it is not on the model. A late fee, a bounced
    // cheque and a violation fine all carry the debtor named on the document that caused them —
    // `IssueInvoiceService::issue()` documents it, and a blanket equality rule refused it.
    $other = makeTenant(['name' => 'Stated On The Document']);

    $invoice = Invoice::create([
        'lease_id' => $this->lease->id,
        'tenant_id' => $other->id,
        'asset_id' => $this->here->id,
        'status' => 'draft',
        'issue_date' => '2026-03-01', 'due_date' => '2026-03-15',
        'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'paid_amount' => 0, 'balance' => 0,
    ]);

    expect($invoice->exists)->toBeTrue()
        ->and((int) $invoice->tenant_id)->toBe((int) $other->id);
});
