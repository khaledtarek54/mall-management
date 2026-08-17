<?php

use App\Models\Invoice;
use App\Models\Lease;
use App\Models\Tenant;
use App\Support\Search\OptionDisplay;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;

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

it('refuses an invoice billed to someone other than the agreement\'s party', function () {
    // Proven reachable before the guard existed: the invoice form offered a free tenant picker
    // beside the lease picker, so raising a document against Cilantro's lease and billing another
    // retailer was two clicks and no warning. It bills a party who never agreed to the charge and
    // ages into THEIR receivables.
    $other = makeTenant(['name' => 'Someone Else']);

    $attributes = [
        'lease_id' => $this->lease->id,
        'asset_id' => $this->here->id,
        'status' => 'draft',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-15',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'paid_amount' => 0, 'balance' => 0,
    ];

    expect(fn () => Invoice::create($attributes + ['tenant_id' => $other->id]))
        ->toThrow(DomainException::class);

    // The control: the agreement's own party is accepted, so the guard refuses the wrong thing
    // rather than everything.
    $ok = Invoice::create($attributes + ['tenant_id' => $this->tenant->id]);
    expect($ok->exists)->toBeTrue();
});

it('refuses re-pointing a saved invoice at a different party', function () {
    // The edit path that could undo the create-time rule. Both sides of the pair are watched,
    // because re-pointing the AGREEMENT reaches the same wrong state as re-pointing the debtor.
    $invoice = makeInvoice($this->lease);
    $other = makeTenant(['name' => 'Late Arrival']);

    expect(fn () => $invoice->update(['tenant_id' => $other->id]))->toThrow(DomainException::class);

    expect((int) $invoice->fresh()->tenant_id)->toBe((int) $this->tenant->id);
});
