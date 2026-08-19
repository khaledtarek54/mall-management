<?php

use App\Models\Invoice;
use App\Models\MarketingBudget;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\LeaseTerminationService;
use App\Support\PropertyIsolation;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\DB;

/**
 * An invoice knows its property; it no longer infers one.
 *
 * Four things used to walk `lease → unit → asset` to answer "which mall is this invoice's?" — the
 * isolation registry, the GL's asset dimension, the document number prefix and the marketing-levy
 * accrual. All four were only ever safe because `lease_id` was NOT NULL. Phase 2 makes it nullable so
 * a unit owner can be billed, and each of these would then have answered null — silently.
 *
 * @see docs/modules/37-unit-owners.md
 */
beforeEach(function () {
    // The GL assertion needs a chart and a posting map, or the journalizer refuses before it ever
    // reaches the property dimension this test is about.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'ZZ']);
    $this->lease = makeLease(makeUnit($this->asset));
});

it('stamps the property of the agreement that raised it', function () {
    $invoice = makeInvoice($this->lease);

    expect($invoice->asset_id)->toBe($this->asset->id)
        ->and($invoice->asset->is($this->asset))->toBeTrue();
});

it('numbers the invoice from its own property, not from a walk through the lease', function () {
    // The prefix is the property's code. Read off the column now, so it stays right for a document
    // raised by something that has no lease at all.
    expect(makeInvoice($this->lease)->number)->toStartWith('INV-ZZ-');
});

it('gives the journal entry its property dimension', function () {
    $invoice = makeInvoice($this->lease);
    $invoice->items()->create([
        'description' => 'Base rent', 'type' => 'base_rent',
        'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000,
    ]);

    $entry = app(LedgerPoster::class)->sync($invoice->fresh());

    // Without a property the entry still balances, still ties out, and quietly vanishes from every
    // per-property P&L and every owner statement. That is why this is asserted rather than assumed.
    expect($entry)->not->toBeNull()
        ->and($entry->asset_id)->toBe($this->asset->id);
});

it('accrues a marketing levy against the invoice property', function () {
    $invoice = makeInvoice($this->lease, ['subtotal' => 0, 'vat_amount' => 0, 'total' => 0, 'balance' => 0]);

    $invoice->items()->create([
        'description' => 'Marketing levy',
        'type' => 'marketing',
        'amount' => 500,
        'vat_rate' => 0,
        'vat_amount' => 0,
        'total' => 500,
    ]);

    $budget = MarketingBudget::forPeriod($this->asset->id, (int) $invoice->issue_date->year);

    expect((float) $budget->fresh()->accrued_amount)->toBe(500.00);
});

it('is isolated by its own column rather than by a chain', function () {
    // The registry entry is the guard: a chain through `lease.unit` would drop an ownership invoice
    // out of every scoped query the moment lease_id is nullable.
    //
    // `isDirect()` rather than a null linkage read: it also fails if Invoice leaves the owned
    // register entirely, where reading the raw key returned null for "absent" and "direct" alike
    // — the same answer for the safe case and the one this test exists to catch.
    expect(PropertyIsolation::isDirect(Invoice::class))->toBeTrue();
});

it('refuses to create or clear an invoice with no property', function () {
    // The column is nullable in the schema only because tightening it means `->change()` on
    // `invoices`, which on SQLite silently drops the CHECK constraints guarding status/eta_status.
    // The non-nullness lives here instead, so prove it is real in both directions.
    expect(fn () => Invoice::create([
        'lease_id' => null,
        'tenant_id' => $this->lease->tenant_id,
        'status' => 'issued',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-08',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'subtotal' => 100, 'vat_amount' => 0, 'total' => 100, 'balance' => 100,
    ]))->toThrow(DomainException::class);

    $invoice = makeInvoice($this->lease);

    expect(fn () => $invoice->update(['asset_id' => null]))->toThrow(DomainException::class);

    // Control — the same invoice saves happily when the property is left alone, so the refusals
    // above are about the property and not about the fixture. `refresh()` first: a refused update
    // leaves the REJECTED value on the in-memory model, so without it the next save re-submits the
    // null and throws again — which would read as the guard misfiring rather than holding.
    $invoice->refresh();
    $invoice->update(['notes' => 'untouched']);
    expect($invoice->fresh()->asset_id)->toBe($this->asset->id);
});

it('backfilled every invoice that already existed', function () {
    // The migration's own guarantee. A row that resolved to nothing would have thrown at migrate
    // time; this asserts the state that guarantee produces, over whatever the fixtures created.
    makeInvoice($this->lease);

    expect(DB::table('invoices')->whereNull('asset_id')->count())->toBe(0);
});

it('keeps an unsettled invoice collectable, and in its own mall, after the lease is terminated', function () {
    // The operator's question: terminating a lease with money still outstanding — does anything move?
    $invoice = makeInvoice($this->lease);

    app(LeaseTerminationService::class)->terminate($this->lease, [
        'termination_date' => '2026-06-30',
        'reason' => 'tested',
    ]);

    $invoice->refresh();

    // The debt survives the tenancy: a terminated lease is not a deleted one (DeletionPolicy refuses
    // that outright), so the receivable stays visible, stays owed, and stays in the mall that earned
    // it. None of that is changed by this work — it is asserted because it is what an operator will
    // ask, and because the property is now frozen on the row rather than re-derived on every read.
    expect((float) $invoice->balance)->toBeGreaterThan(0.0)
        ->and($invoice->asset_id)->toBe($this->asset->id)
        ->and($invoice->lease_id)->toBe($this->lease->id);
});

it('does not let a unit moving to another mall drag historical receivables with it', function () {
    // THE BUG THIS FIXES, and it predates unit owners entirely. `units.asset_id` is editable on the
    // unit form, so a unit can be re-homed. While an invoice inferred its property through
    // `lease → unit → asset`, re-homing silently re-parented EVERY invoice that unit ever raised —
    // issued, paid, GL-posted — into the new mall's reports and owner statement.
    //
    // The journal entry never moved with them (`journal_entries.asset_id` has always been its own
    // column), so the sub-ledger and the ledger would have disagreed, in opposite directions, with
    // nothing raising a hand.
    $invoice = makeInvoice($this->lease);
    $otherMall = makeAsset(['code' => 'YY']);

    $this->lease->unit->update(['asset_id' => $otherMall->id]);

    expect($invoice->fresh()->asset_id)->toBe($this->asset->id)
        // ...and the old inference would now answer the OTHER mall — which is the whole point.
        ->and($this->lease->fresh()->assetId())->toBe($otherMall->id);
});
