<?php

use App\Enums\PartyType;
use App\Enums\UnitOwnershipStatus;
use App\Models\Area;
use App\Models\CamAllocation;
use App\Models\CamExpensePool;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\UnitOwnership;
use App\Services\CamReconciliationService;
use App\Services\SyncCamPoolFromLedgerService;

/**
 * SW-135 and SW-199b — the billed estimate counted the wrong participants and the wrong documents.
 *
 * **SW-135: an owner's assessments were invisible, so he was charged the year twice.** A unit owner
 * pays a monthly صيانة, which `docs/modules/37` and the reconciliation's own comment call the same
 * economic act as a tenant's service-charge estimate — recovery of common cost, billed under the
 * same charge type. On `estimate_basis = billed`, `estimateBilledFor()` was supposed to subtract it.
 * It could not: `billedServiceChargeQuery()` narrowed participants with
 * `whereIn('invoices.lease_id', …)`, and an owner's assessment invoice carries a **null**
 * `lease_id`, which `whereIn` never matches. Every owner's `estimated_paid` was 0.00, so the annual
 * true-up billed his entire year's share a second time — after twelve months of assessments he had
 * already paid — and the pool's own `total_estimated_collected` omitted every one of them.
 *
 * Nothing was loud about it. The pool still tied out (Σ allocated = actual expense by construction),
 * every allocation looked right, and the true-up invoice reads as an ordinary reconciliation charge.
 * The comment beside the call asserted the opposite of what the code did — *"which is exactly what
 * this query sums"* — which is why review would not have caught it either.
 *
 * **SW-199b: a DRAFT invoice counted as billed.** `invoices.status` DEFAULTS to `draft` at the
 * column, so a never-issued invoice is the normal product of any create that omits a status. The
 * filter was a denylist of `cancelled` and `written_off` only. A draft inflated what the pool
 * believed it had collected, which understates the true-up — or, past the pool's total, mints a
 * credit note for money nobody was ever asked for.
 *
 * The participant set is now ONE definition read by both callers: `CamExpensePool`'s two
 * `participant*Query()` methods. The allocator and the estimate cannot disagree about who is in a
 * pool, which is the drift that produced this.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'OWN', 'leasable_area_sqm' => 200]);

    $this->letUnit = makeUnit($this->asset, ['area_sqm' => 100]);
    $this->soldUnit = makeUnit($this->asset, ['area_sqm' => 100]);

    $this->lease = makeLease($this->letUnit, makeTenant(), [
        'commencement_date' => '2026-01-01', 'expiry_date' => '2027-12-31', 'status' => 'active',
    ]);

    $this->ownership = UnitOwnership::create([
        'asset_id' => $this->asset->id,
        'unit_id' => $this->soldUnit->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    $this->pool = CamExpensePool::create([
        'asset_id' => $this->asset->id,
        'period_year' => 2026,
        'pool_code' => CamExpensePool::CODE_CAM,
        'name' => 'Common area 2026',
        'status' => 'draft',
        'estimate_basis' => CamExpensePool::BASIS_BILLED,
        'total_actual_expense' => 100000,
        'total_estimated_collected' => 0,
    ]);
});

/**
 * A service-charge line billed to whichever participant the link names.
 *
 * `$link` is the agreement's own `invoiceLinkAttributes()` shape — `lease_id` for a tenant,
 * `unit_ownership_id` for an owner — so the fixture cannot accidentally invent a third way of
 * saying whose invoice this is.
 */
function assessmentOf(array $link, float $amount, string $status = 'issued', string $on = '2026-06-01'): Invoice
{
    $invoice = Invoice::create(array_merge([
        'asset_id' => test()->asset->id,
        'tenant_id' => $link['lease_id'] ?? null
            ? test()->lease->tenant_id
            : test()->ownership->tenant_id,
        'status' => $status,
        'issue_date' => $on,
        'due_date' => $on,
        'period_start' => $on,
        'period_end' => $on,
        'subtotal' => $amount, 'vat_amount' => 0, 'total' => $amount,
    ], array_filter($link, fn ($v) => $v !== null)));

    InvoiceItem::create([
        'invoice_id' => $invoice->id,
        'type' => 'service_charge',
        'description' => 'Monthly assessment',
        'amount' => $amount, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $amount,
    ]);

    // **The status is re-stamped after the line, and it has to be.** `InvoiceItem::saved` calls
    // `Invoice::syncTotalsFromItems()` → `recomputeTotals()`, whose auto-status block overrides
    // anything not in its manual-override list — and `draft` is not in that list, so writing a line
    // onto a draft PROMOTES it to `issued`, irreversibly (the immutability guard then refuses the
    // way back). That is SW-215, a separate defect: it means an operator who saves a Draft invoice
    // gets an issued one, visible to the tenant and on the books.
    //
    // A draft carrying lines is therefore reachable today only by a write that bypasses the model —
    // an import, a migration, a `saveQuietly()` — which is precisely the population a guard at the
    // read still has to defend, so the filter below is not made redundant by the promotion bug.
    $invoice->newQuery()->whereKey($invoice->getKey())->update(['status' => $status]);

    return $invoice->fresh();
}

it('counts an owner\'s assessments toward what the pool collected', function () {
    assessmentOf($this->lease->invoiceLinkAttributes(), 30000);
    assessmentOf($this->ownership->invoiceLinkAttributes(), 20000);

    // Measured before the fix: 30,000 — the owner's twenty thousand was invisible, because
    // `whereIn('invoices.lease_id', …)` never matches a null.
    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(50000.0);
});

it('subtracts what an owner already paid from his own true-up', function () {
    assessmentOf($this->ownership->invoiceLinkAttributes(), 20000);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $owner = CamAllocation::where('cam_expense_pool_id', $this->pool->id)
        ->whereNotNull('unit_ownership_id')->firstOrFail();

    // Half the mall is his, so his share of the 100,000 pool is 50,000. He has already been
    // assessed 20,000 of it, so the true-up is 30,000.
    //
    // Measured before the fix: estimated_paid 0.00 and a true-up of 50,000 — the whole year again,
    // on top of the twelve assessments he had already paid.
    expect(round((float) $owner->allocated_amount, 2))->toBe(50000.00)
        ->and(round((float) $owner->estimated_paid, 2))->toBe(20000.00)
        ->and(round((float) $owner->true_up_amount, 2))->toBe(30000.00);
});

it('still answers a tenant exactly as it did', function () {
    // The control: the lease branch must be untouched by adding the ownership one.
    assessmentOf($this->lease->invoiceLinkAttributes(), 30000);
    assessmentOf($this->ownership->invoiceLinkAttributes(), 20000);

    app(CamReconciliationService::class)->generateAllocations($this->pool);

    $tenant = CamAllocation::where('cam_expense_pool_id', $this->pool->id)
        ->whereNotNull('lease_id')->firstOrFail();

    expect(round((float) $tenant->estimated_paid, 2))->toBe(30000.00)
        ->and(round((float) $tenant->true_up_amount, 2))->toBe(20000.00);
});

it('keeps the OR inside the participant filter, not around the whole query', function () {
    // **AND binds tighter than OR**, so written flat the clause compiles to
    // `lease_in OR (ownership_in AND status AND type AND period)` — and the branch that escapes is
    // the LEASE one: every invoice of every participant lease, at any status, of any item type, in
    // any year. The obvious "does the tenant's figure move?" control cannot see that, because the
    // lease's own service charge matches the escaping branch either way; what shows it is a lease
    // invoice the filters were supposed to keep OUT.
    assessmentOf($this->lease->invoiceLinkAttributes(), 30000);

    // Right lease, wrong YEAR.
    assessmentOf($this->lease->invoiceLinkAttributes(), 77000, on: '2025-06-01');

    // Right lease, right year, wrong LINE TYPE — a pool subtracts only the codes it declared.
    $rent = assessmentOf($this->lease->invoiceLinkAttributes(), 1);
    $rent->items()->create([
        'type' => 'base_rent', 'description' => 'Rent',
        'amount' => 88000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 88000,
    ]);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(30001.0);
});

it('does not count a DRAFT invoice as billed', function () {
    // `draft` is the column default, so this is what any create that omits a status produces —
    // not an exotic state. Counted, it tells the pool it collected money it never asked for.
    assessmentOf($this->lease->invoiceLinkAttributes(), 30000);
    assessmentOf($this->ownership->invoiceLinkAttributes(), 20000, status: 'draft');

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(30000.0)
        ->and(app(SyncCamPoolFromLedgerService::class)->estimateBilledFor($this->pool, $this->ownership))->toBe(0.0);
});

it('asks "was it billed, and not reversed" — so a write-off counts and a credit note does not', function () {
    // The old list was a denylist of `cancelled` + `written_off`, which answers neither question.
    //
    // A WRITE-OFF forgives a debt that WAS billed and the tenant WAS asked for, so excluding it made
    // the true-up re-charge money the operator had already invoiced and then forgiven — SW-135's own
    // shape through the opposite door. It was a CLIFF too: `WriteOffInvoiceService` moves the status
    // only on a FULL write-off, so 9,999.99 counted in full and 10,000.00 counted as nothing.
    //
    // A CREDIT NOTE reverses the billing itself, and `credited` was counted GROSS — a fully credited
    // 100,000 told the pool it had collected 100,000 it did not.
    $svc = app(SyncCamPoolFromLedgerService::class);
    $link = $this->ownership->invoiceLinkAttributes();

    assessmentOf($link, 4000, status: 'written_off');

    expect($svc->estimateBilledFor($this->pool, $this->ownership))->toBe(4000.0);

    assessmentOf($link, 9000, status: 'credited');
    assessmentOf($link, 100, status: 'cancelled');
    assessmentOf($link, 200, status: 'draft');

    expect($svc->estimateBilledFor($this->pool, $this->ownership))->toBe(4000.0);

    // …and the control, or a filter that excluded everything would satisfy the refusals above: the
    // five statuses of a live debt all count. Dropping `overdue` or `disputed` would understate
    // collections and over-recover from a tenant who is merely late.
    foreach (['issued', 'partially_paid', 'paid', 'overdue', 'disputed'] as $status) {
        assessmentOf($link, 10, status: $status);
    }

    expect($svc->estimateBilledFor($this->pool, $this->ownership))->toBe(4050.0);
});

it('does not subtract an out-of-zone owner from a zone pool', function () {
    // **The fix's own worst failure, caught in review before it shipped.** Leaving the ownership
    // query unscoped looked conservative — `participants()` had never area-scoped ownerships, so
    // allocations would not move. It is the opposite: before the ownership branch existed the
    // estimate subtracted NOTHING for an owner, so an area pool merely over-billed him, which a
    // voided invoice recovers. Unscoped, the estimate subtracts every owner in the MALL's whole year
    // from a ZONE pool's share — turning money owed into an outbound credit note, auto-applied FIFO
    // against live AR. That is the −80,000 failure `CamPoolEstimateScopeTest` exists to prevent,
    // re-entered through the other door, and outbound is the worse direction.
    $zone = Area::create([
        'asset_id' => $this->asset->id, 'name' => 'Food court', 'code' => 'FC',
    ]);
    $this->letUnit->update(['area_id' => $zone->id]);   // the tenant is IN the zone
    // …and the owner's shop is not.

    $this->pool->update(['participant_scope' => CamExpensePool::PARTICIPANTS_AREA, 'participant_area_id' => $zone->id]);

    assessmentOf($this->lease->invoiceLinkAttributes(), 30000);
    assessmentOf($this->ownership->invoiceLinkAttributes(), 66000);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool->fresh()))->toBe(30000.0);

    // And the allocator agrees, because both read the one definition: the out-of-zone owner is not
    // a participant of a food-court pool at all.
    app(CamReconciliationService::class)->generateAllocations($this->pool->fresh());

    expect(CamAllocation::where('cam_expense_pool_id', $this->pool->id)->whereNotNull('unit_ownership_id')->count())
        ->toBe(0);
});

it('does not reach an owner in another mall', function () {
    // The participant filter is a SCOPE, not decoration: widening it to reach ownerships must not
    // widen it to reach the portfolio. Same trap the picker relation-reach had — an `or` branch
    // that escapes the scope it was supposed to sit inside.
    $otherAsset = makeAsset(['code' => 'OTHER']);
    $foreign = UnitOwnership::create([
        'asset_id' => $otherAsset->id,
        'unit_id' => makeUnit($otherAsset, ['area_sqm' => 100])->id,
        'tenant_id' => makeTenant(['party_type' => PartyType::UnitOwner->value])->id,
        'status' => UnitOwnershipStatus::HandedOver->value,
        'started_at' => '2026-01-01',
    ]);

    Invoice::create([
        'asset_id' => $otherAsset->id,
        'tenant_id' => $foreign->tenant_id,
        'unit_ownership_id' => $foreign->id,
        'status' => 'issued',
        'issue_date' => '2026-06-01', 'due_date' => '2026-06-01',
        'period_start' => '2026-06-01', 'period_end' => '2026-06-30',
        'subtotal' => 99000, 'vat_amount' => 0, 'total' => 99000,
    ])->items()->create([
        'type' => 'service_charge', 'description' => 'Other mall',
        'amount' => 99000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 99000,
    ]);

    assessmentOf($this->ownership->invoiceLinkAttributes(), 20000);

    expect(app(SyncCamPoolFromLedgerService::class)->estimateFromInvoices($this->pool))->toBe(20000.0);
});

it('honours the reconciled year, so last year\'s assessments are not this year\'s', function () {
    assessmentOf($this->ownership->invoiceLinkAttributes(), 20000, on: '2025-06-01');
    assessmentOf($this->ownership->invoiceLinkAttributes(), 5000, on: '2026-06-01');

    expect(app(SyncCamPoolFromLedgerService::class)->estimateBilledFor($this->pool, $this->ownership))->toBe(5000.0);
});
