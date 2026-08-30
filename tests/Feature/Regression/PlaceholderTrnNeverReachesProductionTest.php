<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| A placeholder tax registration must stay a TEST-box convenience
|--------------------------------------------------------------------------
| ConfigurationHealth's `seller_tax_identity` row is BLOCKING, and it guards one thing: an invoice
| that titles itself "Tax Invoice" while carrying no registration number. That document is not
| incomplete, it is confidently wrong on the page every tenant files with their own accountant.
|
| Seeding a registration turns that check GREEN. So the placeholder has to be impossible to get by
| accident: it is its own seeder rather than part of the demo/learning data, and it refuses on
| production rather than skipping quietly.
*/

use App\Settings\TaxSettings;
use Database\Seeders\LearningSeeder;
use Database\Seeders\PlaceholderIssuerIdentitySeeder;

it('sets a registration that is obviously not a real one', function () {
    $this->seed(PlaceholderIssuerIdentitySeeder::class);

    $settings = app(TaxSettings::class);

    expect($settings->seller_tax_registration_number)->toBe('000-000-000')
        // Real in shape (nine digits, xxx-xxx-xxx) so the document layout is true to life…
        ->toMatch('/^\d{3}-\d{3}-\d{3}$/');

    // …and unmistakable in content, so nothing produced can be taken for a real tax document.
    expect($settings->seller_legal_name)->toContain('STAGING');
    expect($settings->seller_billing_email)->toEndWith('.invalid');
});

it('refuses to run on production rather than skipping quietly', function () {
    app()->detectEnvironment(fn (): string => 'production');

    // Invoked directly, not through $this->seed(): Laravel wraps a seeder run in production
    // behind its own "are you sure?" confirmation, which would throw first and leave this test
    // green without ever reaching the guard it exists to prove.
    expect(fn () => (new PlaceholderIssuerIdentitySeeder)->run())
        ->toThrow(RuntimeException::class, 'refuses to run on production');

    expect(app(TaxSettings::class)->seller_tax_registration_number)->toBe('');
});

it('is NOT folded into the learning dataset, so that check stays honest there', function () {
    // The control for the whole design: asking for a learning mall must NOT silently satisfy a
    // blocking configuration check the operator has not actually answered.
    $this->seed(LearningSeeder::class);

    expect(app(TaxSettings::class)->seller_tax_registration_number)->toBe('');
});
