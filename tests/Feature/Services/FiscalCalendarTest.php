<?php

use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Services\Accounting\FiscalCalendar;

it('ensureYear opens one fiscal year with twelve monthly periods, idempotently', function () {
    app(FiscalCalendar::class)->ensureYear(2030);
    app(FiscalCalendar::class)->ensureYear(2030); // second call must not duplicate

    $year = FiscalYear::where('year', 2030)->get();
    expect($year)->toHaveCount(1);
    expect(AccountingPeriod::where('fiscal_year_id', $year->first()->id)->count())->toBe(12);
});
