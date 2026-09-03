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
| `docs/operations/GO-LIVE.md` is that list, verified by hand against the code — accurate on the day it was
| written and able to fall out of date silently every day after.
|
| Each check is tested from BOTH sides. A configuration checklist that reports "all clear" because
| its detection is broken is worse than no checklist: it is a green light nobody earned.
*/

use App\Console\Commands\PreflightCommand;
use App\Filament\Admin\Pages\ConfigurationHealth as Page;
use App\Models\AccountingPeriod;
use App\Models\ChargeCode;
use App\Models\Employee;
use App\Models\Payroll;
use App\Models\PayrollRate;
use App\Models\TaxCode;
use App\Models\Vendor;
use App\Settings\TaxSettings;
use App\Support\ConfigurationHealth;
use App\Support\PayrollRates;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountingSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Lang;
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

it('says nobody can ask about a bill until a contact is set', function () {
    // EG-05 turned a WRONG contact into NO contact — the documents used to print a fabricated
    // address that reached nobody. The honest version of that fix is silence PLUS a row telling the
    // operator to fill it; silence alone just moves the failure somewhere quieter.
    $settings = app(TaxSettings::class);
    $settings->seller_billing_email = '';
    $settings->save();

    expect(healthCheck('billing_contact')['ok'])->toBeFalse()
        // Advisory: an invoice with no contact line is still a valid invoice.
        ->and(healthCheck('billing_contact')['severity'])->toBe(ConfigurationHealth::ADVISORY);

    $settings->seller_billing_email = 'billing@eltizam.example';
    $settings->save();

    expect(healthCheck('billing_contact')['ok'])->toBeTrue()
        ->and(healthCheck('billing_contact')['detail'])->toBe('billing@eltizam.example');
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

/** An active employee, so the payroll check has a roster to have an opinion about. */
function payrollRoster(): Employee
{
    return Employee::create([
        'asset_id' => makeAsset()->id,
        'code' => 'E-'.uniqid(),
        'name' => 'Karim Nabil',
        'hire_date' => '2026-01-01',
        'base_salary' => 9000,
        'payment_method' => 'bank',
    ]);
}

/** An approved payroll run. Created as a draft and approved, which is the real path. */
function approvedPayrollFor(Employee $employee, array $attrs = []): Payroll
{
    $run = Payroll::create(array_merge([
        'asset_id' => $employee->asset_id,
        'period_month' => now()->startOfMonth()->toDateString(),
        'gross_salaries' => 9000,
        'salary_tax' => 0,
        'social_insurance' => 0,
        'employer_social_insurance' => 0,
        'net_paid' => 9000,
        'paid_from' => 'bank',
    ], $attrs));

    $run->update(['status' => 'approved']);

    return $run;
}

it('says nothing about payroll while nobody is on the roster', function () {
    // The control the advisory branch needs: a fresh install ships every rate at 0, and shouting
    // about it before anyone is employed is a nag — which teaches the operator to ignore the page.
    expect(healthCheck('payroll_rates_configured')['ok'])->toBeTrue()
        ->and(healthCheck('payroll_rates_configured')['detail'])
        ->toBe(__('admin.config_health.payroll_no_roster'));
});

it('advises once there is a roster and every statutory rate is still nil', function () {
    payrollRoster();

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeFalse()
        // Advice, not an accusation: the settings screen's own help offers "leave at 0 and enter it
        // per employee" as a supported way to work, so a blocking row here would contradict it.
        ->and(healthCheck('payroll_rates_configured')['severity'])->toBe(ConfigurationHealth::ADVISORY);

    // A rung, not a setting (EG-03) — the check reads the ladder in force today.
    // Clear the ladder first: the migration seeds a rung dated 1 Jan 2026 (the statutory band),
    // which SUPERSEDES an earlier one — a test rung dated 2000 would never be the one in force.
    PayrollRate::query()->delete();
    // Clear the ladder first: the migration seeds a rung dated 1 Jan 2026 (the statutory band),
    // which SUPERSEDES an earlier one — a test rung dated 2000 would never be the one in force.
    PayrollRate::query()->delete();
    PayrollRate::create(['effective_from' => '2000-01-01', 'employee_social_insurance_rate' => 11.0]);
    PayrollRates::flush();

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeTrue();
});

it('goes quiet when the deductions are keyed per run rather than set as rates', function () {
    // The posture the field help explicitly supports. Rates stay at 0, the run carries real
    // deductions, and the operator must not be left with a standing red dot for working that way.
    $employee = payrollRoster();
    approvedPayrollFor($employee, ['salary_tax' => 700, 'social_insurance' => 990, 'net_paid' => 7310]);

    expect(PayrollRates::for()->salaryTaxRate)->toBe(0.0)
        ->and(healthCheck('payroll_rates_configured')['ok'])->toBeTrue();
});

it('blocks on an approved run that withheld nothing at all', function () {
    // The failure the check exists for: net = gross on every payslip, and no liability to the
    // authority anywhere in the books, on a run that looks exactly like a working one.
    $employee = payrollRoster();
    approvedPayrollFor($employee);

    $check = healthCheck('payroll_rates_configured');

    expect($check['ok'])->toBeFalse()
        ->and($check['severity'])->toBe(ConfigurationHealth::BLOCKING)
        ->and($check['count'])->toBe(1)
        ->and($check['category'])->toBe(ConfigurationHealth::PAYROLL);
});

it('clears when a corrective run in the same month carries the deductions', function () {
    // The remedy the impact line actually promises, and the reason the check asks about a MONTH
    // rather than a run. `Payroll::booted()` refuses a dirty `salary_tax` on a run whose original
    // status was approved, so counting individual runs would pin this row for the life of the
    // install with no remedy except cancelling a real payroll to satisfy a checklist — and a red
    // dot nobody can clear is one everybody learns to ignore.
    $employee = payrollRoster();
    $month = now()->startOfMonth()->toDateString();
    approvedPayrollFor($employee, ['period_month' => $month]);

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeFalse();

    // The original stays wrong — and stays uneditable, which is exactly why the remedy has to be a
    // second document rather than a correction in place.
    $stale = Payroll::query()->where('status', 'approved')->firstOrFail();
    expect(fn () => $stale->update(['salary_tax' => 700]))->toThrow(DomainException::class);

    // …and the stale run is CANCELLED before the corrective one is approved. Both of these runs are
    // lump sums — `approvedPayrollFor()` writes no payslip lines — so leaving the first approved
    // would post a second full salaries expense for a month that had one, which is SW-100 exactly
    // (measured: 24,000 booked for a 12,000 month, with every entry internally balanced and nothing
    // downstream objecting). Cancelling is the escape the refusal names, and it is also the honest
    // accounting: a run that stated the wrong deductions did not happen as stated.
    // `fresh()` first: the refused update above left `salary_tax` dirty on this instance, and the
    // immutability guard reads `getDirty()`, so cancelling would carry the rejected edit with it.
    $stale->fresh()->update(['status' => 'cancelled']);

    approvedPayrollFor($employee, [
        'period_month' => $month,
        'salary_tax' => 700, 'social_insurance' => 990, 'net_paid' => 7310,
    ]);

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeTrue();
});

it('is never silenced by a payroll month somebody typed into the future', function () {
    // `period_month` is an unbounded DatePicker, so without the clamp a mistyped year becomes the
    // permanent "latest payroll month" and hides every real one behind it.
    $employee = payrollRoster();
    approvedPayrollFor($employee, ['period_month' => now()->startOfMonth()->toDateString()]);
    approvedPayrollFor($employee, [
        'period_month' => now()->addYears(2)->startOfMonth()->toDateString(),
        'salary_tax' => 700, 'social_insurance' => 990, 'net_paid' => 7310,
    ]);

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeFalse();
});

it('states the advisory case in its own words, not the blocking one', function () {
    // The failure this replaced: the advisory branch borrowed the blocking sentence and told an
    // operator with no payroll at all that ":count approved runs withheld nothing" — with a count
    // of zero, about books that carry nothing. Asserted on the RENDERED page, because the defect
    // was invisible to a test that read only `ok`, `severity` and `count`.
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());
    payrollRoster();

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeFalse();

    Livewire::test(Page::class)
        ->assertSee('Every statutory rate is still 0')
        ->assertDontSee('Your latest payroll month has');
});

it('tells an operator with no roster what it found, rather than asserting evidence it lacks', function () {
    // The green row used to read "approved payroll runs carry their statutory deductions" on an
    // install that had never run payroll — a claim about evidence that did not exist, while the
    // sentence describing the real state was computed, translated, and unreachable.
    $this->actingAs(makeUser('super_admin'));
    Filament::setTenant(makeAsset());

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeTrue();

    Livewire::test(Page::class)->assertSee(__('admin.config_health.payroll_no_roster'));
});

it('advises the mall onboarded last week, even while another has been paying for a year', function () {
    // The false negative the per-property loop exists for — and which survived on the ADVISORY
    // branch after the blocking one was fixed. One mall's year of correct payroll must not silence
    // the advisory for a mall that has a roster and has never run one.
    $running = payrollRoster();
    approvedPayrollFor($running, ['salary_tax' => 700, 'social_insurance' => 990, 'net_paid' => 7310]);

    // A second property with a live roster and nothing approved.
    payrollRoster();

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeFalse()
        ->and(healthCheck('payroll_rates_configured')['severity'])->toBe(ConfigurationHealth::ADVISORY);
});

it('never claims a payroll month it does not have', function () {
    // The green row used to read "your latest payroll month carries its statutory deductions" the
    // moment the rates were set — on an install that had never approved a run. Doing what the page
    // asked should not produce a sentence about evidence that does not exist.
    payrollRoster();

    // The statutory figures are a dated rung now (EG-03), not three flat settings.
    // Clear the ladder first: the migration seeds a rung dated 1 Jan 2026 (the statutory band),
    // which SUPERSEDES an earlier one — a test rung dated 2000 would never be the one in force.
    PayrollRate::query()->delete();
    PayrollRate::create([
        'effective_from' => '2000-01-01',
        'salary_tax_rate' => 10,
        'employee_social_insurance_rate' => 11,
        'employer_social_insurance_rate' => 18.75,
    ]);
    PayrollRates::flush();
    $check = healthCheck('payroll_rates_configured');

    expect($check['ok'])->toBeTrue()
        ->and($check['detail'])->toBe(__('admin.config_health.payroll_awaiting_first_run'))
        ->and($check['detail'])->not->toBe(__('admin.config_health.payroll_ok'));
});

it('names which mall and which month withheld nothing', function () {
    // A bare count is unactionable in a portfolio: each property is judged on its OWN latest month,
    // so "your latest payroll month" is several different months at once.
    $employee = payrollRoster();
    approvedPayrollFor($employee, ['period_month' => now()->startOfMonth()->toDateString()]);

    $check = healthCheck('payroll_rates_configured');

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain($employee->asset->name)
        ->and($check['detail'])->toContain(now()->format('m/Y'));
});

it('does not show one mall its neighbour\'s broken payroll', function () {
    // `Employee` and `Payroll` are both #[PropertyOwned] and this page is open to mall_admin, a
    // property-restricted role. An unscoped count would show them a red dot for a mall whose
    // records they cannot open — and no other check on this page reads property-owned data.
    $mine = makeAsset();
    $theirs = makeAsset();

    $neighbour = Employee::create([
        'asset_id' => $theirs->id, 'code' => 'E-'.uniqid(), 'name' => 'Not mine',
        'hire_date' => '2026-01-01', 'base_salary' => 9000, 'payment_method' => 'bank',
    ]);
    approvedPayrollFor($neighbour);

    // MY OWN roster, so the check gets past its roster gate and actually runs the payroll queries.
    // Without this the test returned at `payroll_no_roster` and would have stayed green with the
    // payroll scope deleted — proving the Employee scope while claiming to prove the Payroll one.
    Employee::create([
        'asset_id' => $mine->id, 'code' => 'E-'.uniqid(), 'name' => 'Mine',
        'hire_date' => '2026-01-01', 'base_salary' => 9000, 'payment_method' => 'bank',
    ]);

    $this->actingAs(makeUser('mall_admin', [$mine->id]));

    // The control: unscoped, the neighbour's run IS the finding — so a green result below is the
    // scope working, not the fixture failing to reproduce.
    expect(Payroll::query()->where('status', 'approved')->count())->toBe(1);

    $check = healthCheck('payroll_rates_configured');

    // Not "green": this operator has a roster of their own and has approved nothing, so the honest
    // answer is their OWN advisory. What must not happen is the neighbour's BLOCKING row — a red
    // dot naming a mall whose payroll they cannot open.
    expect($check['severity'])->toBe(ConfigurationHealth::ADVISORY)
        ->and($check['detail'])->not->toContain($theirs->name)
        // …and specifically because the roster gate was PASSED and the payroll queries found
        // nothing of mine — not because there was no roster to judge.
        ->and($check['detail'])->not->toBe(__('admin.config_health.payroll_no_roster'));

    // The control: unrestricted, the same data IS a blocking row — so the assertion above is the
    // scope working, not the fixture failing to reproduce.
    $this->actingAs(makeUser('super_admin'));

    expect(healthCheck('payroll_rates_configured')['severity'])->toBe(ConfigurationHealth::BLOCKING);
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
    // The optional fourth key. A check whose advisory state is ordinary states its own case; the
    // page falls back to `impact` when the key is absent, so this is asserted only where defined.
    foreach (ConfigurationHealth::run() as $check) {
        $advisory = "admin.config_health.checks.{$check['key']}.advisory";

        if (! Lang::has($advisory, 'en', false)) {
            continue;
        }

        expect(Lang::has($advisory, 'ar', false))->toBeTrue(
            "{$check['key']} states its advisory case in English only, so an Arabic reader gets the "
            .'blocking sentence instead.'
        );
    }
});

it('refuses the page to someone who cannot see the settings', function () {
    $this->actingAs(makeUser('marketing'));

    expect(Page::canAccess())->toBeFalse();

    // The control — the role that owns the settings can open it.
    $this->actingAs(makeUser('super_admin'));

    expect(Page::canAccess())->toBeTrue();
});

it('sees a head-office payroll run that belongs to no single property', function () {
    // `payrolls.asset_id` is nullable — `#[PropertyOwned(portfolioRowsWhenNull: true)]` — and a bare
    // `whereIn` excludes NULL, so a head-office run was invisible to a property-restricted reader:
    // exactly the run nobody owns and therefore nobody chases. The `orWhereNull` that fixes it
    // shipped with NO test, and deleting it left the whole suite green, because the one restricted
    // case had no null-asset fixture and every other payroll case runs as super_admin, where the
    // scope closure short-circuits.
    $mine = makeAsset();

    // A roster at my mall, so the check gets past its roster gate and actually runs the payroll
    // queries — without this it returns at `payroll_no_roster` and proves nothing.
    $employee = Employee::create([
        'asset_id' => $mine->id, 'code' => 'E-'.uniqid(), 'name' => 'Mine',
        'hire_date' => '2026-01-01', 'base_salary' => 9000, 'payment_method' => 'bank',
    ]);

    // Rates SET, so the advisory branch (all-nil + awaiting a first run) cannot fire and turn this
    // red for an unrelated reason — the first cut of this test passed with the fix deleted for
    // exactly that reason. Only the blocking branch can produce a red below.
    // The statutory figures are a dated rung now (EG-03), not three flat settings.
    // Clear the ladder first: the migration seeds a rung dated 1 Jan 2026 (the statutory band),
    // which SUPERSEDES an earlier one — a test rung dated 2000 would never be the one in force.
    PayrollRate::query()->delete();
    PayrollRate::create([
        'effective_from' => '2000-01-01',
        'salary_tax_rate' => 10,
        'employee_social_insurance_rate' => 11,
        'employer_social_insurance_rate' => 18.75,
    ]);
    PayrollRates::flush();
    // The control first: with no head-office run at all, the row is green. So the red below is
    // caused by the null-asset run and nothing else.
    $this->actingAs(makeUser('mall_admin', [$mine->id]));
    expect(healthCheck('payroll_rates_configured')['ok'])->toBeTrue();

    // The head-office run: approved, no deductions, no property.
    approvedPayrollFor($employee, ['asset_id' => null]);

    expect(healthCheck('payroll_rates_configured')['ok'])->toBeFalse(
        'A run with no property belongs to everyone, including this reader.');
});

it('does not admit next month as the latest run on the 31st', function () {
    // `now()->addMonth()->startOfMonth()` overflows on the 29th–31st: 2026-08-31 + 1 month is
    // 2026-10-01, so the "not future" bound became the month AFTER next and a genuinely future run
    // was admitted as "latest" — hiding a broken current month on seven days of the year. The old
    // `endOfMonth()` expression could never do that, so the rewrite was strictly worse in the
    // clamp's own class. Existing coverage used `addYears(2)`, which the overflowed bound still
    // excluded.
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-08-31 09:00'));

    $asset = makeAsset();
    $employee = Employee::create([
        'asset_id' => $asset->id, 'code' => 'E-'.uniqid(), 'name' => 'Mine',
        'hire_date' => '2026-01-01', 'base_salary' => 9000, 'payment_method' => 'bank',
    ]);

    // August is broken — no deductions — and September has been approved ahead of time, correctly.
    approvedPayrollFor($employee, ['period_month' => '2026-08-01']);
    approvedPayrollFor($employee, [
        'period_month' => '2026-09-01',
        'salary_tax' => 500, 'social_insurance' => 400, 'employer_social_insurance' => 900,
        'net_paid' => 7200,
    ]);

    $this->actingAs(makeUser('super_admin'));

    // With the overflow, September is "latest", it carries deductions, and August's breakage is
    // invisible. The bound must exclude September so the broken August is still the latest month.
    expect(healthCheck('payroll_rates_configured')['ok'])->toBeFalse();
});

/*
|--------------------------------------------------------------------------
| ...and a check nobody runs is not a check
|--------------------------------------------------------------------------
| Everything above tests the CHECKS. Nothing tested whether anything outside a browser could run
| them, and nothing could: eight checks, no command, no route, and `atriom:preflight` — the gate
| `deploy.sh` fires on every release — asked `atriom:health` and the two data audits and stopped.
| So an install could pass every automated pre-deploy gate with no seller TRN, an incomplete
| posting map and no open accounting period, and the only thing between that and a real month was
| somebody remembering to open a page.
|
| The same shape `HealthChecksAreWiredConformanceTest` was written for, one layer out: there the
| risk was a check missing from `run()`, here it is a registry missing from the gate.
*/

it('puts the configuration checks on the gate the deploy actually runs', function () {
    // Read off the command's own step list rather than by running it: `atriom:preflight` shells
    // four commands, one of which reconciles the books, and what is being pinned is the WIRING.
    $steps = (new ReflectionClass(PreflightCommand::class))
        ->getConstant('STEPS');

    expect(array_column($steps, 'command'))->toContain('atriom:config-health');
});

it('exits non-zero on a BLOCKING gap, so the deploy reports it', function () {
    $settings = app(TaxSettings::class);
    $settings->seller_tax_registration_number = '';
    $settings->save();

    $this->artisan('atriom:config-health')
        ->expectsOutputToContain('seller_tax_identity')
        ->assertExitCode(1);
});

it('exits zero when only ADVISORY rows are open', function () {
    // The rule that keeps the step readable. `billing_contact` is advisory and empty on most
    // installs for a while; a step that is permanently red is a step people stop reading, which
    // costs more than the advisory is worth. Paired with the refusal above, so this cannot pass by
    // the command having no verdict at all.
    $settings = app(TaxSettings::class);
    $settings->seller_tax_registration_number = '123-456-789';
    $settings->seller_billing_email = '';
    $settings->save();

    expect(collect(ConfigurationHealth::run())
        ->firstWhere('key', 'billing_contact')['ok'])->toBeFalse();

    $this->artisan('atriom:config-health')->assertExitCode(0);
});

it('fails on an advisory row under --strict, which is the cutover posture', function () {
    $settings = app(TaxSettings::class);
    $settings->seller_tax_registration_number = '123-456-789';
    $settings->seller_billing_email = '';
    $settings->save();

    $this->artisan('atriom:config-health', ['--strict' => true])->assertExitCode(1);
});

it('prints the impact sentence the screen prints, not the raw detail', function () {
    // The detail is BLANK on several checks — the failure is an absence — so a command printing
    // `detail` would render the row that matters most as "seller_tax_identity · FAIL · ". Both
    // renderers resolve through one `sentenceFor()` for that reason, and so a wording fix reaches
    // the screen and the deploy log together.
    $settings = app(TaxSettings::class);
    $settings->seller_tax_registration_number = '';
    $settings->save();

    $check = healthCheck('seller_tax_identity');

    expect($check['detail'])->toBe('')
        ->and(ConfigurationHealth::sentenceFor($check))
        ->toBe(__('admin.config_health.checks.seller_tax_identity.impact', ['detail' => '', 'count' => 0]));
});
