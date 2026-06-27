<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Number;

/**
 * Numbers must always render in Western/Latin digits (0-9), never Arabic-Indic
 * (٠-٩ / ۰-۹), even when the UI is in Arabic. Guards number_format, Carbon date
 * formatting, and the Laravel Number helper (which Filament ->money() uses).
 */
it('renders every number in Latin digits even under the Arabic locale', function () {
    app()->setLocale('ar');

    $eastern = '/[\x{0660}-\x{0669}\x{06F0}-\x{06F9}]/u'; // Arabic-Indic + extended
    $date = Carbon::parse('2026-06-15');

    $samples = [
        'number_format' => number_format(1234567.5, 2),
        'carbon_format' => $date->format('d M Y'),
        'carbon_isoFormat' => $date->locale('ar')->isoFormat('MMM YYYY'),
        'carbon_translatedFormat' => $date->locale('ar')->translatedFormat('d M Y'),
        'number_helper_format' => Number::format(1234567.5),
        'number_helper_currency' => Number::currency(1234.5, 'EGP'),
        'number_helper_percentage' => Number::percentage(12.5),
    ];

    foreach ($samples as $label => $value) {
        expect(preg_match($eastern, $value))->toBe(0, "Arabic-Indic digits found in {$label}: {$value}");
    }
});
