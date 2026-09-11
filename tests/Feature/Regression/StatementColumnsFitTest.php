<?php

/*
|--------------------------------------------------------------------------
| The statement's columns must hold what the statement prints (2026-08-17)
|--------------------------------------------------------------------------
| Column widths were being chosen by eye and checked by reading the rendered PDF, which is how the
| same page produced two rounds of the same defect:
|
|   round 1 — STATUS at 8% broke its header to "STATU S" and its value to "PARTIAL LY PAID"
|   round 2 — widening STATUS took the width from its neighbours, so the invoice number broke to
|             "INV- AW-202604-0001", the due date to "24/08/202 6", and paid to "100,000.0 0"
|
| Nothing failed either time. A PDF has no assertions, and the numbers were all correct — only
| unreadable, on the one document that goes to the customer.
|
| So this measures instead of looking. mPDF exposes `GetStringWidth()` against the very font metrics
| it renders with, so the check is the renderer's own arithmetic rather than a guess about it: for
| each column, the widest string it can hold must fit its share of the page after padding.
|
| It is deliberately a table of literals rather than a sweep of the Blade. A sweep would have to
| parse CSS to learn the widths and would then be wrong in exactly the way the template is wrong;
| this states the intended layout so that changing the template without changing the intent fails.
*/

use App\Support\ValueSets;
use Mpdf\Mpdf;

/**
 * The usable text width of the statement page, in mm.
 *
 * A4 is 210mm and the Blade's `@page` rule sets 36px side margins (36 ÷ 96 × 25.4).
 */
function statementUsableWidthMm(): float
{
    return 210.0 - 2 * (36 / 96 * 25.4);
}

/** Width of a string in mm, as mPDF itself will lay it out. */
function statementTextWidthMm(Mpdf $mpdf, string $text, float $pt, bool $mono = false): float
{
    $mpdf->SetFont($mono ? 'dejavusansmono' : 'dejavusans', '', $pt);

    return $mpdf->GetStringWidth($text);
}

it('gives every column of the open-invoices table room for its widest content', function () {
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => storage_path('app/mpdf')]);

    $usable = statementUsableWidthMm();

    // Horizontal cell padding, both sides: `table.data td { padding: 6px 6px }` → 6px = 1.5875mm.
    $padding = 2 * (6 / 96 * 25.4);

    // The widest realistic value per column, with the width the template allots it. The document
    // number is the longest FIXED string on the page — `INV-{ASSET}-{YYYYMM}-{NNNN}` — and the
    // status is the longest translated one.
    $columns = [
        // label                width%   sample                       pt    mono
        // The ledger's own columns (2026-09-11) — the reference is the same document number.
        ['ledger date',         11,     '24/08/2026',                 8.5,  false],
        ['ledger reference',    18,     'INV-AW-202604-0001',         8.0,  true],
        ['ledger balance',      14,     '1,300,500.00',               8.5,  false],
        ['invoice number',      19,     'INV-AW-202604-0001',         8.0,  true],
        ['period',              14,     'Apr – Jun 2026',             8.5,  false],
        ['due date',            12,     '24/08/2026',                 8.5,  false],
        ['total',               13,     '1,240,300.00',               8.5,  false],
        ['paid',                12,     '100,000.00',                 8.5,  false],
        ['balance',             13,     '140,300.00',                 8.5,  false],
    ];

    $tooNarrow = [];

    foreach ($columns as [$label, $pct, $sample, $pt, $mono]) {
        $available = $usable * $pct / 100 - $padding;
        $needed = statementTextWidthMm($mpdf, $sample, $pt, $mono);

        if ($needed > $available) {
            $tooNarrow[] = sprintf('%s: needs %.1fmm, has %.1fmm (%d%%) — "%s"',
                $label, $needed, $available, $pct, $sample);
        }
    }

    expect($tooNarrow)->toBe([], "These columns will wrap mid-value on the tenant's statement:\n  "
        .implode("\n  ", $tooNarrow));
});

it('gives each table TOTAL room for its figure — the row the body measurements miss', function () {
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => storage_path('app/mpdf')]);

    $usable = statementUsableWidthMm();
    $padding = 2 * (6 / 96 * 25.4);

    // A totals cell is NOT the column above it: it carries an "EGP " prefix, renders bold, and may
    // span columns. Measuring the body alone passed while "Total Outstanding" wrapped to
    // "EGP / 300,500.00" directly beneath two rows that fitted.
    // The ledger and deposit footers (2026-09-11) carry NO "EGP " prefix — the currency sits on
    // their label, because a prefixed six-digit figure wrapped in a 12% cell — so their samples
    // are bare. The open-invoice footer keeps its prefix and its spanned width.
    $totals = [
        // label                 spanned width%   sample
        ['total outstanding',    13 + 17,        'EGP 1,300,500.00'],
        ['ledger closing',       14,             '1,300,500.00'],
        ['ledger period debit',  14,             '1,080,100.00'],
        ['deposit held',         14,             '1,152,000.00'],
    ];

    $tooNarrow = [];

    foreach ($totals as [$label, $pct, $sample]) {
        $available = $usable * $pct / 100 - $padding;
        // Bold is wider than regular; mPDF resolves the bold metrics from the same family.
        $mpdf->SetFont('dejavusans', 'B', 8.5);
        $needed = $mpdf->GetStringWidth($sample);

        if ($needed > $available) {
            $tooNarrow[] = sprintf('%s: needs %.1fmm, has %.1fmm (%d%%) — "%s"',
                $label, $needed, $available, $pct, $sample);
        }
    }

    expect($tooNarrow)->toBe([], "These totals will wrap on the tenant's statement:\n  "
        .implode("\n  ", $tooNarrow));

    // …and the measurement above is only true of the template while the two 14% footers carry
    // NO "EGP " prefix — the sample is bare because the cell is. A prefix put back in the blade
    // would wrap the closing balance again while this test measured the wrong string.
    $blade = file_get_contents(resource_path('views/tenants/statement.blade.php'));
    expect($blade)->not->toContain("EGP {{ number_format(\$ledger['closing']")
        ->and($blade)->not->toContain("EGP {{ number_format(\$deposit['held']");
});

it('holds the longest status label on one line, in both languages', function () {
    $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'tempDir' => storage_path('app/mpdf')]);

    // The pill adds its own 6px side padding inside the cell's 6px.
    $available = statementUsableWidthMm() * 17 / 100 - 2 * (6 / 96 * 25.4) - 2 * (6 / 96 * 25.4);

    $tooNarrow = [];

    // Derived from the value set, not a list — a status added tomorrow is measured too, which is the
    // half of this that a hand-written sample would miss.
    foreach (ValueSets::allowed('invoices', 'status') ?? [] as $status) {
        foreach (['en', 'ar'] as $locale) {
            $label = trans('admin.statuses.invoice.'.$status, [], $locale);

            if (! is_string($label) || str_contains($label, 'admin.statuses')) {
                continue;
            }

            // The pill renders uppercase in LTR, which is wider than the stored casing.
            $rendered = $locale === 'en' ? mb_strtoupper($label) : $label;
            $needed = statementTextWidthMm($mpdf, $rendered, 7.0);

            if ($needed > $available) {
                $tooNarrow[] = sprintf('%s [%s]: needs %.1fmm, has %.1fmm — "%s"',
                    $status, $locale, $needed, $available, $rendered);
            }
        }
    }

    // Vacuity guard: a typo in the key path would measure nothing and pass.
    expect(ValueSets::allowed('invoices', 'status'))->not->toBeEmpty();

    expect($tooNarrow)->toBe([], "These status pills will wrap on the tenant's statement:\n  "
        .implode("\n  ", $tooNarrow));
});

it('keeps every table\'s columns summing to the full width, and no more', function () {
    $blade = file_get_contents(resource_path('views/tenants/statement.blade.php'));

    // Each header block, anchored on its section title and sliced to </thead> — an anchor on the
    // first column's LABEL sits after that column's own width and silently measures one fewer.
    // `strpos` must FIND the anchor: a renamed title returns false, `substr` from 0 measures the
    // wrong table, and the test reports on a table it did not mean to.
    $tables = [
        'admin.statement.account_ledger' => 6,      // the ledger (2026-09-11)
        'admin.statement.deposit_account' => 6,     // the deposit account
        'admin.statement.balance_by_invoice' => 7,  // the open-invoice breakdown (was "open invoices")
    ];

    foreach ($tables as $anchor => $columns) {
        $start = strpos($blade, "__('{$anchor}')");
        expect($start)->not->toBeFalse("anchor {$anchor} not found in the template");
        $end = strpos($blade, '</thead>', $start);
        $header = substr($blade, $start, $end - $start);

        preg_match_all('/width:(\d+)%/', $header, $m);
        $widths = array_map('intval', $m[1]);

        // Over 100% and mPDF rescales every column, quietly undoing whatever was tuned here; under,
        // and the table stops short of the margin for no reason.
        expect($widths)->toHaveCount($columns, "{$anchor}: column count")
            ->and(array_sum($widths))->toBe(100, "{$anchor}: widths sum");
    }
});
