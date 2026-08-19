<?php

/*
|--------------------------------------------------------------------------
| "Is it alive" and "is it set up" are different questions
|--------------------------------------------------------------------------
| `atriom:health` answers the first. Nothing answered the second, and the two fail differently: a
| perfectly healthy installation bills every tenant through a floor rate because nobody classified
| the charge codes, and issues tax invoices with no registration number on them. Neither shows up
| as an outage; neither is visible until a tenant asks why they cannot reclaim their VAT.
|
| `docs/GO-LIVE.md` is that list, verified by hand against the code — accurate on the day it was
| written and able to fall out of date silently every day after.
|
| Each check is tested from BOTH sides. A configuration checklist that reports "all clear" because
| its detection is broken is worse than no checklist: it is a green light nobody earned.
*/

use App\Filament\Admin\Pages\ConfigurationHealth as Page;
use App\Models\AccountingPeriod;
use App\Models\ChargeCode;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Settings\TaxSettings;
use App\Support\ConfigurationHealth;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    // The whole accounting reference set, including the fiscal calendar — the period check has
    // nothing to pass against without it, and seeding the four catalogues by hand omitted exactly
    // that (which this test caught).
    $this->seed(AccountingSeeder::class);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** One check, by key. */
function healthCheck(string $key): array
{
    return collect(ConfigurationHealth::run())->firstWhere('key', $key);
}

it('reports a missing seller tax registration number, and stops once it is set', function () {
    // A tax invoice without it is not one: the tenant cannot support an input-VAT deduction, so
    // the operator's compliance gap arrives as their tenants' complaint.
    $settings = app(TaxSettings::class);
    $settings->seller_tax_registration_number = '';
    $settings->save();

    expect(healthCheck('seller_tax_identity')['ok'])->toBeFalse()
        ->and(healthCheck('seller_tax_identity')['severity'])->toBe(ConfigurationHealth::BLOCKING);

    $settings->seller_tax_registration_number = '123-456-789';
    $settings->save();

    expect(healthCheck('seller_tax_identity')['ok'])->toBeTrue()
        ->and(healthCheck('seller_tax_identity')['detail'])->toBe('123-456-789');
});

it('names the charge codes nobody has ruled on for tax', function () {
    // The seeded catalogue classifies every code, so the control comes first.
    expect(healthCheck('charge_codes_classified')['ok'])->toBeTrue();

    ChargeCode::create([
        'code' => 'key_money',
        'name_en' => 'Key money', 'name_ar' => 'خلو رجل',
        'posting_role' => 'misc_income',
        'is_active' => true,
    ]);
    ChargeCode::flushLookupCaches();

    $check = healthCheck('charge_codes_classified');

    expect($check['ok'])->toBeFalse()
        ->and($check['count'])->toBe(1)
        // Named, not counted: "one charge code is unclassified" sends the operator hunting.
        ->and($check['detail'])->toContain('key_money');
});

it('ignores an inactive charge code, which bills nothing', function () {
    // A code switched off is not a gap. Counting it would make the page cry wolf about a decision
    // the operator already made.
    ChargeCode::create([
        'code' => 'retired_fee',
        'name_en' => 'Retired', 'name_ar' => 'ملغى',
        'posting_role' => 'misc_income',
        'is_active' => false,
    ]);
    ChargeCode::flushLookupCaches();

    expect(healthCheck('charge_codes_classified')['ok'])->toBeTrue();
});

it('says which tax codes cannot be used yet, as advice rather than a fault', function () {
    // **This test used to assert the opposite**, and the change is the point. Stamp and schedule tax
    // shipped switched off, so the check reported them and this asserted a FAILING row. Both
    // families were commissioned on 2026-08-19 — accounts, posting roles, and journalizers that post
    // them to their own accounts — so the seeded catalogue is now clean.
    //
    // Asserting the clean state alone would leave a check that could quietly stop checking, so the
    // second half breaks the data and requires it to notice. That is the F-08 lesson: reading a
    // check tells you what it compares, only mutation tells you what it catches.
    expect(healthCheck('tax_codes_commissioned')['ok'])->toBeTrue(
        'the seeded catalogue has an uncommissioned taxable code: '
        .healthCheck('tax_codes_commissioned')['detail']
    );

    // An accountant adds a taxable code and has not entered its rate or named its account yet.
    TaxCode::create([
        'code' => 'SCHD_77', 'name_en' => 'Schedule 77%', 'name_ar' => 'ضريبة الجدول ٧٧٪',
        'family' => TaxCode::FAMILY_SCHEDULE, 'direction' => TaxCode::SALES,
        'treatment' => TaxCode::STANDARD, 'posting_role' => null,
        'invoice_label' => 'SCHD 77%', 'is_active' => false,
    ]);

    $check = healthCheck('tax_codes_commissioned');

    expect($check['ok'])->toBeFalse()
        ->and($check['severity'])->toBe(ConfigurationHealth::ADVISORY)
        ->and($check['count'])->toBeGreaterThan(0)
        ->and($check['detail'])->toContain('SCHD_77');
});

it('catches withholding switched on with nothing to withhold', function () {
    // Off is fine and is the shipped state.
    $settings = app(TaxSettings::class);
    $settings->wht_enabled = false;
    $settings->save();

    expect(healthCheck('withholding_configured')['ok'])->toBeTrue();

    // On, with no default and no supplier carrying a code: every payment deducts nothing, which
    // looks exactly like it working and leaves the operator liable for the tax they did not deduct.
    $settings->wht_enabled = true;
    $settings->wht_default_tax_code = '';
    $settings->save();

    expect(healthCheck('withholding_configured')['ok'])->toBeFalse()
        ->and(healthCheck('withholding_configured')['severity'])->toBe(ConfigurationHealth::BLOCKING);

    // A supplier carrying their own code is enough — a portfolio default is not the only way.
    Vendor::create([
        'name' => 'SupplyCo', 'status' => Vendor::STATUS_ACTIVE,
        'withholding_tax_code' => 'WH_3_P',
    ]);

    expect(healthCheck('withholding_configured')['ok'])->toBeTrue();
});

it('reports when today falls outside an open period', function () {
    // A MISSING period is allowed by the posting-date guard and a CLOSED one is refused, so a
    // calendar that has not been extended does not fail loudly — it stops accepting entries inside
    // the job that posts them. This is where that becomes visible.
    expect(healthCheck('open_accounting_period')['ok'])->toBeTrue();

    AccountingPeriod::query()->update(['status' => 'closed']);

    expect(healthCheck('open_accounting_period')['ok'])->toBeFalse();

    AccountingPeriod::query()->delete();

    expect(healthCheck('open_accounting_period')['ok'])->toBeFalse();
});

it('classifies every check into a known category and severity', function () {
    foreach (ConfigurationHealth::run() as $check) {
        expect(in_array($check['category'], ConfigurationHealth::CATEGORIES, true))
            ->toBeTrue("{$check['key']} is in unknown category '{$check['category']}'");
        expect(in_array($check['severity'], [ConfigurationHealth::BLOCKING, ConfigurationHealth::ADVISORY], true))
            ->toBeTrue("{$check['key']} has unknown severity '{$check['severity']}'");
    }
});

it('describes every check in English and Arabic', function () {
    // An untranslated key reaches production reading "admin.config_health.checks.x.impact" — on the
    // page whose whole job is telling somebody what is wrong.
    $missing = [];

    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);

        foreach (ConfigurationHealth::run() as $check) {
            foreach (['name', 'impact', 'ok'] as $field) {
                $key = "admin.config_health.checks.{$check['key']}.{$field}";

                if (__($key) === $key) {
                    $missing[] = "{$check['key']}.{$field} [{$locale}]";
                }
            }
        }
    }

    app()->setLocale('en');

    expect($missing)->toBe([], 'Undescribed checks: '.implode(', ', $missing));
})->group('i18n');

it('renders the page for someone who may see the settings', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());

    Livewire::test(Page::class)
        ->assertOk()
        // The impact line reached the screen — a checklist that renders its keys instead of its
        // sentences is the failure this page exists to avoid.
        ->assertSee(__('admin.config_health.checks.seller_tax_identity.name'));
});

it('refuses the page to someone who cannot see the settings', function () {
    $this->actingAs(makeUser('marketing'));

    expect(Page::canAccess())->toBeFalse();

    // The control — the role that owns the settings can open it.
    $this->actingAs(makeUser('super_admin'));

    expect(Page::canAccess())->toBeTrue();
});
