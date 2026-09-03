<?php

use App\Models\Invoice;
use App\Models\InvoiceWriteOff;
use App\Services\Reports\ReportService;
use Carbon\CarbonImmutable;

/**
 * **The dashboard, the sidebar badge and the report all had to mean the same thing by "overdue".**
 *
 * Five surfaces answered it with no status list at all — `balance > 0 AND due_date < now` on the
 * RAW balance (SW-234). That counts a DRAFT nobody issued and a WRITTEN-OFF debt the operator
 * forgave, and it quotes a partially written-off invoice at its full figure — while the AR report
 * those tiles deep-link into reads `stillOwed()`. The divergence was latent rather than present:
 * on a book where only `overdue` and `partially_paid` rows are past due, all three agree. The first
 * forgiven or contested arrear is what makes the card disagree with the page it opens.
 *
 * **And `stillOwed()` inside a self-relation was a syntax error (SW-235).**
 * `collectableBalanceSql($table)` documented `$table` as the defence against Laravel aliasing a
 * self-relation's inner table — and `scopeWhereCollectable()` passed `$query->getQuery()->from`,
 * which is the WHOLE expression, `invoices as laravel_reserved_0`. Concatenated in front of
 * `.balance` that parses on neither driver. The docblock's claim that *"the common path cannot get
 * it wrong"* was the opposite of true.
 */
beforeEach(function () {
    $this->asset = makeAsset(['code' => 'OVD']);
    $this->lease = makeLease(makeUnit($this->asset), makeTenant());

    $this->overdue = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'overdue',
        'issue_date' => '2026-01-01', 'due_date' => '2026-01-10',
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'paid_amount' => 0, 'balance' => 10000,
    ]);
});

it('does not count a draft nobody issued', function () {
    makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'draft',
        'issue_date' => '2026-01-01', 'due_date' => '2026-01-10',
        'subtotal' => 90000, 'vat_amount' => 0, 'total' => 90000, 'paid_amount' => 0, 'balance' => 90000,
    ]);

    expect(Invoice::query()->stillOwed()->where('due_date', '<', now())->count())->toBe(1);
});

it('quotes a partially written-off invoice at what is still owed', function () {
    // A write-off deliberately leaves `balance` standing — it is not a settlement channel — so the
    // raw column shows forgiven money as outstanding on every one of these surfaces.
    InvoiceWriteOff::create([
        'invoice_id' => $this->overdue->id,
        'tenant_id' => $this->overdue->tenant_id,
        'entry_date' => '2026-02-01',
        'amount' => 4000,
        'reason' => 'Goodwill.',
    ]);

    $owed = Invoice::query()->stillOwed()->where('due_date', '<', now())
        ->with('writeOffs')->get()->sum(fn (Invoice $i): float => $i->collectableBalance());

    expect($owed)->toBe(6000.0)
        // …and it agrees with the report the dashboard tile links to.
        ->and((float) app(ReportService::class)
            ->arCollectionsByTenant(CarbonImmutable::parse('2026-03-01'))->sole()['total'])->toBe(6000.0);
});

it('drops one that was written off in full', function () {
    InvoiceWriteOff::create([
        'invoice_id' => $this->overdue->id,
        'tenant_id' => $this->overdue->tenant_id,
        'entry_date' => '2026-02-01',
        'amount' => 10000,
        'reason' => 'Uncollectable.',
    ]);

    expect(Invoice::query()->stillOwed()->where('due_date', '<', now())->count())->toBe(0);
});

it('compiles inside a self-relation instead of throwing', function () {
    // SW-235. `whereHas` on a self-relation aliases the inner table; the scope used to concatenate
    // the whole `invoices as laravel_reserved_0` expression in front of `.balance`. This EXECUTES
    // the query rather than merely compiling it, because the old form produced valid-looking PHP
    // and invalid SQL — the failure only exists at the driver.
    $fee = makeInvoice($this->lease, [
        'asset_id' => $this->asset->id, 'status' => 'issued',
        'issue_date' => '2026-02-01', 'due_date' => '2026-02-10',
        'subtotal' => 500, 'vat_amount' => 0, 'total' => 500, 'paid_amount' => 0, 'balance' => 500,
    ]);
    $this->overdue->forceFill(['late_fee_invoice_id' => $fee->id])->saveQuietly();

    $sql = Invoice::query()->whereHas('lateFeeInvoice', fn ($q) => $q->stillOwed())->toSql();

    expect($sql)->toContain('laravel_reserved_0.balance')
        ->not->toContain('invoices as laravel_reserved_0.balance');

    expect(Invoice::query()->whereHas('lateFeeInvoice', fn ($q) => $q->stillOwed())->pluck('id'))
        ->toContain($this->overdue->id);
});
