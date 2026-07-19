<?php

it('flags invoices past their due date as overdue', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit);

    $overdue = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(5),
    ]);

    $current = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->addDays(5),
    ]);

    expect($overdue->isOverdue())->toBeTrue();
    expect($current->isOverdue())->toBeFalse();
});

it('counts days overdue from due_date to today', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit);

    $invoice = makeInvoice($lease, [
        'status' => 'issued',
        'due_date' => now()->subDays(10),
    ]);

    expect($invoice->daysOverdue())->toBeGreaterThanOrEqual(9)
        ->toBeLessThanOrEqual(11);
});

it('reports zero days overdue for current invoices', function () {
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit);
    $invoice = makeInvoice($lease, ['due_date' => now()->addDays(5)]);

    expect($invoice->daysOverdue())->toBe(0);
});

it('auto-generates a unique invoice number with the asset code and period', function () {
    // Distinctive code proves the number is derived from the property, not a
    // hardcoded prefix.
    $asset = makeAsset(['code' => 'XY']);
    $unit = makeUnit($asset);
    $lease = makeLease($unit);

    $a = makeInvoice($lease, ['issue_date' => '2026-02-01']);
    $b = makeInvoice($lease, ['issue_date' => '2026-02-15']);
    $c = makeInvoice($lease, ['issue_date' => '2026-03-01']);

    expect($a->number)->toMatch('/^INV-XY-202602-\d{4}$/');
    expect($b->number)->toMatch('/^INV-XY-202602-\d{4}$/');
    expect($c->number)->toMatch('/^INV-XY-202603-\d{4}$/');
    expect($a->number)->not->toBe($b->number);
});

// NOTE: the tests for the legacy Invoice::recalculateBalance() were removed with the method — it
// set paid_amount/balance directly, bypassing the AR single source of truth (Invoice::recomputeTotals,
// which derives paid from captured payments + applied credits). recomputeTotals is tested elsewhere.
