<?php

use App\Services\Reports\MonthlyCloseReportPdfService;
use Carbon\CarbonImmutable;

it('builds a PDF binary for a given period', function () {
    $period = CarbonImmutable::create(2026, 2, 1);

    $pdf = app(MonthlyCloseReportPdfService::class)->build($period);

    expect($pdf)->toBeString();
    expect(strlen($pdf))->toBeGreaterThan(1000);
    // PDF magic number — every valid PDF starts with %PDF-
    expect(substr($pdf, 0, 5))->toBe('%PDF-');
});

it('renders RTL when the locale is Arabic without throwing', function () {
    app()->setLocale('ar');

    try {
        $pdf = app(MonthlyCloseReportPdfService::class)->build(CarbonImmutable::create(2026, 2, 1));
        expect(substr($pdf, 0, 5))->toBe('%PDF-');
    } finally {
        app()->setLocale('en');
    }
});

it('produces a period-stamped filename', function () {
    $svc = app(MonthlyCloseReportPdfService::class);

    expect($svc->filename(CarbonImmutable::create(2026, 3, 1)))
        ->toBe('atriom-monthly-close-2026-03.pdf');
    expect($svc->filename(CarbonImmutable::create(2025, 12, 1)))
        ->toBe('atriom-monthly-close-2025-12.pdf');
});
