<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\PartyType;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Models\AccountMapping;
use App\Models\Announcement;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\Department;
use App\Models\DepositTransaction;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FixedAsset;
use App\Models\Floor;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\LeaseOption;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PostDatedCheque;
use App\Models\RecurringExpense;
use App\Models\ServicePlan;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\Trade;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Models\VendorDocument;
use App\Services\Accounting\MintBankLedgerAccountService;
use App\Services\BillSecurityDepositService;
use App\Services\BillUnitOwnershipsService;
use App\Services\DepreciationService;
use App\Services\GeneratePayrollService;
use App\Services\LeaseCreationService;
use App\Services\MonthlyBillingService;
use App\Services\PercentageRentCalculationService;
use App\Services\PostDatedChequeService;
use App\Services\RaiseCorrectiveWorkOrderService;
use App\Services\TenantRequestService;
use App\Services\VendorBillService;
use App\Support\LeaseTerm;
use App\Support\MorphMap;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

/**
 * NILE GATE MALL — the month-long SOAK property, seeded BESIDE whatever is already on the box.
 *
 * ## What this is for
 *
 * A real-time test of the system running unattended for a month: the scheduler fires every night
 * and the question each morning is whether the money and the business state moved the way a mall
 * accountant would expect. `ValPlazaSeeder` gives an EMPTY mall for showing an action; `DemoSeeder`
 * gives a mall mid-life for proving the system holds a portfolio. Neither gives the scheduler
 * anything to DO on a known date — this one does.
 *
 * Every date here is relative to the day the seeder runs (`$today`), so the calendar below is a
 * calendar of what should happen on which day AFTER seeding, whatever day that is:
 *
 *   D+1  02:45  Guardian Security contract renewal notice is already overdue → alert
 *        02:45  Carrefour COI lapses in 17 days · Al Tazaj COI already lapsed → alerts
 *        04:00  Al Tazaj's LAST month invoice is past due + grace → first late fee
 *   D+2  02:30  weekly cleaning inspection plan is due → preventive work order raised
 *        05:30  Nile Clean retainer (day 7 of the month) → DRAFT vendor bill from the schedule
 *   D+3         Koshary cheque #1 matures — held, not banked → pdc:scan-maturing reports it
 *   D+4  06:00  Orange kiosk's current-month invoice (due D+3) is overdue → owner alert + tenant reminder
 *        12:00  scheduled announcement goes out
 *   D+5         Carrefour's 10-cheque series starts maturing (one a month)
 *   10th 08:00  sales:scan-missing-declarations — Carrefour + Al Tazaj never declared LAST month
 *   D+7  02:30  generator monthly test-run plan is due → work order
 *   D+8  02:30  Guardian Security contract (ended D+7) → expired
 *   D+10 05:30  Carrefour's rent anniversary → leases:apply-escalations steps the rent +7%
 *   15th 05:30  municipal waste levy schedule → expense recorded
 *   17th 07:30  sales:estimate-missing — estimated declarations for the two that never declared
 *   D+15 02:30  quarterly fire-safety plan due → work order
 *        05:30  Guardian retainer (day 20) — the CONTRACT expired on D+7: does the schedule still bill?
 *   D+20 06:45  Fit Zone renewal option's notice window CLOSES → alert
 *   D+20 02:45  Delta Elevators COI lapses → alert
 *   25th 05:30  telecom schedule → expense recorded
 *   28th 03:30  September depreciation posted for the two fixed assets
 *   month-end   Cairo Optics lease EXPIRES → leases:expire (05:15 next day) frees A-01, holdover card
 *   1st  02:00  monthly billing for every active lease · 02:30 owner assessments · 01:30 marketing budgets
 *   Mon  08:00  pdc:scan-coverage — Carrefour's cheques run out 2 years before his lease does
 *   Fri  03:00  accounting:sync-ledger --all · 04:00 billing:reconcile --deep
 *
 * ## Rules it obeys
 *
 *   - **Additive and refuses to run twice.** It never touches another property's rows, and it aborts
 *     if the NG asset already exists, because document numbers are allocated at write time and a
 *     second run would mint a second set.
 *   - **Everything money goes through the service that owns it** — leases through
 *     `LeaseCreationService`, invoices through `MonthlyBillingService::runForPeriod()` (the exact
 *     code the 02:00 job runs), deposits through `BillSecurityDepositService`, cheques through
 *     `PostDatedChequeService`, payroll through `GeneratePayrollService`, assessments through
 *     `BillUnitOwnershipsService`. A hand-written invoice would be a fixture; these are documents.
 *   - **Receipts name the bank they went through** (`BankAccount::defaultFor()`), so the statement
 *     matcher has something honest to match.
 *   - Reference data is REQUIRED, not re-seeded: a box that has no chart or posting map is not one
 *     to soak-test, so it aborts rather than laying down a second copy.
 *
 *     php artisan db:seed --class='Database\Seeders\NileGateSeeder'
 */
class NileGateSeeder extends Seeder
{
    public const CODE = 'NG';

    public const NAME = 'Nile Gate Mall';

    private const EMAIL_DOMAIN = 'nilegate.test';

    /** @var list<array{code: string, floor: string, category: string, area: float}> */
    private const UNITS = [
        ['code' => 'A-01', 'floor' => 'Ground', 'category' => 'retail',        'area' => 60],
        ['code' => 'A-02', 'floor' => 'Ground', 'category' => 'retail',        'area' => 75],
        ['code' => 'A-03', 'floor' => 'Ground', 'category' => 'retail',        'area' => 90],
        ['code' => 'A-04', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 120],
        ['code' => 'A-05', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 150],
        ['code' => 'A-06', 'floor' => 'Ground', 'category' => 'kiosk',         'area' => 15],
        ['code' => 'A-07', 'floor' => 'Ground', 'category' => 'service',       'area' => 45],
        ['code' => 'A-08', 'floor' => 'Ground', 'category' => 'retail',        'area' => 85],
        ['code' => 'A-09', 'floor' => 'Ground', 'category' => 'service',       'area' => 40],
        ['code' => 'A-10', 'floor' => 'Ground', 'category' => 'retail',        'area' => 200],
        ['code' => 'B-01', 'floor' => '1',      'category' => 'retail',        'area' => 300],
        ['code' => 'B-02', 'floor' => '1',      'category' => 'retail',        'area' => 110],
        ['code' => 'B-03', 'floor' => '1',      'category' => 'office',        'area' => 130],
        ['code' => 'B-04', 'floor' => '1',      'category' => 'wellness',      'area' => 180],
        ['code' => 'B-05', 'floor' => '1',      'category' => 'retail',        'area' => 95],
        ['code' => 'B-06', 'floor' => '1',      'category' => 'retail',        'area' => 70],
        ['code' => 'B-07', 'floor' => '1',      'category' => 'kiosk',         'area' => 25],
        ['code' => 'B-08', 'floor' => '1',      'category' => 'food_beverage', 'area' => 160],
        ['code' => 'B-09', 'floor' => '1',      'category' => 'storage',       'area' => 50],
        ['code' => 'B-10', 'floor' => '1',      'category' => 'office',        'area' => 140],
    ];

    /** Staff who can operate on the property (assignment is what makes a restricted role SEE it). */
    private const STAFF_EMAILS = [
        'manager@mall.test', 'leasing@mall.test', 'accounting@mall.test', 'viewer@mall.test',
        'operations@mall.test', 'hr@mall.test',
    ];

    private CarbonImmutable $today;

    private Asset $asset;

    private ?User $admin = null;

    private string $password = '';

    /** @var array<string, Tenant> */
    private array $tenants = [];

    /** @var array<string, Lease> */
    private array $leases = [];

    /** @var array<string, Vendor> */
    private array $vendors = [];

    /** @var array<string, VendorContract> */
    private array $contracts = [];

    /** @var list<int> invoice ids that are deposit bills — never auto-settled by the history pass */
    private array $depositInvoiceIds = [];

    /** @var list<string> */
    private array $notes = [];

    public function run(): void
    {
        $this->today = CarbonImmutable::today();

        if (Asset::query()->where('code', self::CODE)->exists()) {
            $this->command?->error(self::NAME.' ('.self::CODE.') already exists — this seeder is additive and never runs twice. Nothing changed.');

            return;
        }

        if (AccountMapping::query()->count() === 0 || ChargeCode::query()->count() === 0) {
            $this->command?->error('No posting map / charge codes on this database. Run atriom:install (or a reference seeder) first — a mall that bills and posts nothing is not a soak test.');

            return;
        }

        $this->admin = User::query()->where('email', 'admin@mall.test')->first()
            ?? User::role('super_admin')->first();
        $this->password = (string) config('demo.user_password');

        $this->command?->info('🏬 Seeding '.self::NAME.' — the month-long soak property (D0 = '.$this->today->toDateString().')');

        $this->seedProperty();
        $this->seedBankAccounts();
        $this->seedTenants();

        // History is silent: sixty invoices issued over a year would otherwise ring sixty bells
        // dated today and queue sixty e-mails. The CURRENT month's documents notify for real below.
        $dispatcher = Notification::getFacadeRoot();
        Notification::fake();

        $this->seedLeases();
        $this->seedDeposits();
        $this->seedBillingHistory(untilExclusive: $this->today->startOfMonth());
        $this->seedOwners();
        $this->seedVendors();
        $this->seedVendorBillsAndExpenses();
        $this->seedFixedAssets();
        $this->seedPayroll();
        $this->seedSalesDeclarations();

        Notification::swap($dispatcher);

        $this->seedCurrentMonthBilling();
        $this->restoreHistoricDueDates();
        $this->settleHistory();
        $this->seedPostDatedCheques();
        $this->seedLeaseOptions();
        $this->seedRecurringExpenses();
        $this->seedServicePlans();
        $this->seedTenantRequests();
        $this->seedAnnouncement();

        $this->command?->info('📒 Posting the general ledger from every source document…');
        Artisan::call('accounting:sync-ledger', ['--all' => true]);

        $this->report();
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Property
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function seedProperty(): void
    {
        $leasable = collect(self::UNITS)->sum('area');

        $this->asset = Asset::create([
            'code' => self::CODE,
            'name' => self::NAME,
            'type' => 'mall',
            'address' => 'Ring Road, New Cairo',
            'city' => 'New Cairo',
            'country' => 'Egypt',
            'total_area_sqm' => 3200,
            'leasable_area_sqm' => $leasable,
            'currency' => 'EGP',
            'is_active' => true,
        ]);

        if ($owner = User::query()->where('email', 'owner@atriom.test')->first()) {
            $this->asset->propertyOwners()->syncWithoutDetaching([
                $owner->id => ['ownership_percentage' => 100, 'started_at' => $this->today->startOfYear()->toDateString()],
            ]);
        }

        $staff = User::query()->whereIn('email', self::STAFF_EMAILS)->pluck('id');
        $this->asset->staff()->syncWithoutDetaching(
            $staff->mapWithKeys(fn (int $id) => [$id => ['assigned_at' => $this->today->toDateString()]])->all(),
        );

        foreach (self::UNITS as $row) {
            Unit::create([
                'asset_id' => $this->asset->id,
                'code' => $row['code'],
                'floor_id' => $this->floor($row['floor'])->id,
                'category' => $row['category'],
                'area_sqm' => $row['area'],
                'status' => 'vacant',
            ]);
        }

        $this->command?->info('   Property: '.count(self::UNITS).' units on 2 floors, '.number_format((float) $leasable).' m² leasable; '.$staff->count().' staff assigned');
    }

    private function floor(string $label): Floor
    {
        [$code, $level] = match (strtolower($label)) {
            'ground', 'g' => ['G', 0],
            default => [(string) (int) $label, (int) $label],
        };

        return Floor::firstOrCreate(
            ['asset_id' => $this->asset->id, 'code' => $code],
            ['name' => $label, 'level' => $level],
        );
    }

    /**
     * Two banks, two purposes, each on its own chart leaf minted through the app's own method —
     * the arrangement `BankAccount::assertLedgerAccountIsItsOwn()` requires and the statement
     * matcher depends on. The deposits account is the DEFAULT for its purpose, so a deposit receipt
     * fills itself in with NBE while a rent receipt beside it fills in with CIB.
     */
    private function seedBankAccounts(): void
    {
        $mint = app(MintBankLedgerAccountService::class);

        $operating = $mint->mint(self::NAME.' — CIB operating', $this->asset->id, 'حساب نايل جيت مول — البنك التجاري الدولي (تشغيل)');
        BankAccount::create([
            'asset_id' => $this->asset->id,
            'name' => 'CIB — operating',
            'bank_name' => 'Commercial International Bank',
            'account_number' => '100-4410-002211',
            'iban' => 'EG380019000500000000441002211',
            'currency' => 'EGP',
            'purpose' => BankAccount::PURPOSE_OPERATING,
            'is_default' => true,
            'ledger_account_id' => $operating?->id,
            'is_active' => true,
            'notes' => 'Rent collections and supplier payments.',
        ]);

        $deposits = $mint->mint(self::NAME.' — NBE tenant deposits', $this->asset->id, 'حساب نايل جيت مول — البنك الأهلي (تأمينات المستأجرين)');
        BankAccount::create([
            'asset_id' => $this->asset->id,
            'name' => 'NBE — tenant deposits',
            'bank_name' => 'National Bank of Egypt',
            'account_number' => '900-4410-007788',
            'iban' => 'EG210003000600000000441007788',
            'currency' => 'EGP',
            'purpose' => BankAccount::PURPOSE_DEPOSITS,
            'is_default' => true,
            'ledger_account_id' => $deposits?->id,
            'is_active' => true,
            'notes' => 'Security deposits held on behalf of tenants.',
        ]);

        $this->command?->info('   Bank: CIB operating ('.($operating?->code ?? 'unmapped').') + NBE deposits ('.($deposits?->code ?? 'unmapped').')');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Tenants + leases
    // ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string, array<string, mixed>> */
    private function tenantRows(): array
    {
        return [
            'carrefour' => ['name' => 'Carrefour Express',  'legal' => 'Majid Al Futtaim Hypermarkets Egypt LLC', 'contact' => 'Amr Hassan',      'portal' => true,  'locale' => null, 'coi_days' => 17],
            'tazaj'     => ['name' => 'Al Tazaj',           'legal' => 'Al Tazaj Fakieh Egypt SAE',               'contact' => 'Yasmine Kamel',   'portal' => true,  'locale' => 'ar', 'coi_days' => -20],
            'optics'    => ['name' => 'Cairo Optics',       'legal' => 'Cairo Optics Trading LLC',                'contact' => 'Hany Boulos',     'portal' => false, 'locale' => null, 'coi_days' => 240],
            'nano'      => ['name' => 'Nano Pharmacy',      'legal' => 'Nano Pharmacies SAE',                     'contact' => 'Dr. Rania Fathy', 'portal' => false, 'locale' => 'ar', 'coi_days' => 240],
            'fitzone'   => ['name' => 'Fit Zone Gym',       'legal' => 'Fit Zone Fitness Egypt LLC',              'contact' => 'Omar Selim',      'portal' => false, 'locale' => null, 'coi_days' => 240],
            'koshary'   => ['name' => 'Koshary Abou Tarek', 'legal' => 'Abou Tarek Restaurants SAE',              'contact' => 'Tarek Mahmoud',   'portal' => false, 'locale' => 'ar', 'coi_days' => 240],
            'bershka'   => ['name' => 'Bershka',            'legal' => 'Bershka Egypt LLC',                       'contact' => 'Nour El-Din',     'portal' => false, 'locale' => null, 'coi_days' => 240],
            'orange'    => ['name' => 'Orange Kiosk',       'legal' => 'Orange Egypt for Telecommunications SAE', 'contact' => 'Mostafa Adel',    'portal' => false, 'locale' => null, 'coi_days' => 240],
        ];
    }

    private function seedTenants(): void
    {
        $hashed = Hash::make($this->password);

        $i = 0;
        foreach ($this->tenantRows() as $key => $row) {
            $email = $key.'@'.self::EMAIL_DOMAIN;

            $tenant = Tenant::create([
                'name' => $row['name'],
                'legal_name' => $row['legal'],
                'type' => 'company',
                'email' => $email,
                'password' => $row['portal'] ? $hashed : null,
                'phone' => '+2010'.str_pad((string) (44000000 + $i), 8, '0', STR_PAD_LEFT),
                'whatsapp' => '+2010'.str_pad((string) (44000000 + $i), 8, '0', STR_PAD_LEFT),
                'tax_id' => '4410000'.str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT),
                'address' => self::NAME.', Ring Road, New Cairo',
                'address_governorate' => 'Cairo',
                'address_city' => 'New Cairo',
                'address_street' => 'Ring Road',
                'address_building_number' => (string) (20 + $i),
                'contact_person' => $row['contact'],
                'locale' => $row['locale'],
                'status' => 'active',
            ]);

            // Compliance file: the certificate of insurance is the one that is chased.
            $coiExpiry = $this->today->addDays($row['coi_days']);
            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => TenantDocument::TYPE_INSURANCE_COI,
                'reference' => 'POL-NG-'.strtoupper(substr(md5($email), 0, 6)),
                'issuer' => ['Misr Insurance', 'AXA Egypt', 'Allianz Egypt'][$i % 3],
                'issued_on' => $coiExpiry->subYear()->toDateString(),
                'expires_on' => $coiExpiry->toDateString(),
                'coverage_amount' => 1000000,
            ]);
            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => TenantDocument::TYPE_TAX_CARD,
                'reference' => $tenant->tax_id,
                'issuer' => 'مصلحة الضرائب المصرية',
            ]);
            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => TenantDocument::TYPE_COMMERCIAL_REGISTER,
                'reference' => (string) (61000 + $i),
                'issuer' => 'السجل التجاري',
            ]);

            if ($row['portal']) {
                TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'name' => $row['contact'],
                    'email' => $email,
                    'password' => $hashed,
                    'is_admin' => true,
                ]);
            }

            $this->tenants[$key] = $tenant;
            $i++;
        }

        $this->command?->info('   Tenants: '.count($this->tenants).' retailers (2 with portal logins)');
    }

    /**
     * Seven live leases and one draft, each written so that SOMETHING about it happens inside the
     * month. Dates are relative to D0 — see the calendar in the class docblock.
     *
     * @return array<string, array<string, mixed>>
     */
    private function leaseRows(): array
    {
        $d0 = $this->today;
        $thisMonth = $d0->startOfMonth();

        return [
            // The anchor. Rent anniversary on D+10 → the escalation sweep steps it that morning.
            'carrefour' => ['unit' => 'B-01', 'commencement' => $d0->addDays(10)->subYear(), 'term' => 36, 'rent' => 90000, 'service' => 15000, 'escalation' => 7.0,
                'pct' => ['threshold' => 1500000, 'rate' => 5.0]],
            // F&B, pays late: last month unpaid → late fee on D+1, dunning, arrears.
            'tazaj' => ['unit' => 'A-04', 'commencement' => $thisMonth->subMonths(6), 'term' => 24, 'rent' => 30000, 'service' => 6000, 'escalation' => 7.0,
                'pct' => ['threshold' => 400000, 'rate' => 6.0]],
            // Expires at the END of this month → leases:expire frees the shop, holdover decision.
            'optics' => ['unit' => 'A-01', 'commencement' => $d0->endOfMonth()->addDay()->subYear()->startOfDay(), 'term' => 12, 'rent' => 15000, 'service' => 3000, 'escalation' => 0.0],
            // Commenced mid-month LAST month → the first invoice is a prorated stub. Deposit billed, unpaid.
            'nano' => ['unit' => 'A-07', 'commencement' => $thisMonth->subMonth()->day(16), 'term' => 36, 'rent' => 12000, 'service' => 2500, 'escalation' => 7.0],
            // Carries the lease options (renewal window closing D+20, expansion window opening D+7).
            'fitzone' => ['unit' => 'B-04', 'commencement' => $thisMonth->subMonths(8), 'term' => 60, 'rent' => 45000, 'service' => 9000, 'escalation' => 7.0],
            // Pays by post-dated cheque — one matures on D+3.
            'koshary' => ['unit' => 'A-05', 'commencement' => $thisMonth->subMonths(3), 'term' => 36, 'rent' => 35000, 'service' => 7000, 'escalation' => 7.0],
            // Commenced on the 1st of THIS month → first invoice this month, unpaid → overdue on D+4.
            'orange' => ['unit' => 'A-06', 'commencement' => $thisMonth, 'term' => 12, 'rent' => 6000, 'service' => 1000, 'escalation' => 7.0],
        ];
    }

    private function seedLeases(): void
    {
        $create = app(LeaseCreationService::class);

        foreach ($this->leaseRows() as $key => $row) {
            $unit = $this->unit($row['unit']);

            $lease = $create->create([
                'tenant_mode' => 'existing',
                'tenant_id' => $this->tenants[$key]->id,
                'lease' => [
                    'unit_id' => $unit->id,
                    'commencement_date' => $row['commencement']->toDateString(),
                    'term_months' => $row['term'],
                    'base_rent_monthly' => $row['rent'],
                    'service_charge_monthly' => $row['service'],
                    'escalation_rate' => $row['escalation'],
                    'payment_terms_days' => 7,
                ],
            ]);

            if (isset($row['pct'])) {
                $lease->update([
                    'has_percentage_rent' => true,
                    'requires_sales_reporting' => true,
                    'percentage_rent_threshold' => $row['pct']['threshold'],
                    'percentage_rent_rate' => $row['pct']['rate'],
                    'percentage_rent_calculation_type' => 'artificial',
                ]);
            }

            $this->leases[$key] = $lease->fresh();
        }

        // The draft: signed for next month, not yet activated. It must NOT bill on the 1st — that
        // is one of the checks. Created the way `CreateLease` creates one: the row, then the
        // standard charge pair, then the projected ladder.
        $bershkaStart = $this->today->startOfMonth()->addMonth();
        $unit = $this->unit('B-02');
        $draft = Lease::create([
            'unit_id' => $unit->id,
            'tenant_id' => $this->tenants['bershka']->id,
            'status' => 'draft',
            'commencement_date' => $bershkaStart->toDateString(),
            'expiry_date' => LeaseTerm::expiryFrom($bershkaStart->toDateString(), 36),
            'term_months' => 36,
            'base_rent_monthly' => 40000,
            'service_charge_monthly' => 8000,
            'currency' => 'EGP',
            'security_deposit' => 120000,
            'escalation_rate' => 7.0,
            'escalation_type' => 'fixed_percent',
            'payment_terms_days' => 7,
        ]);
        LeaseCreationService::seedStandardCharges($draft, 40000, 8000, $bershkaStart);
        $this->leases['bershka'] = $draft;

        $this->command?->info('   Leases: '.(count($this->leases) - 1).' active + 1 draft (Bershka, B-02, from '.$bershkaStart->toDateString().')');
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->where('asset_id', $this->asset->id)->where('code', $code)->firstOrFail();
    }

    /**
     * The deposit pot in every state the register distinguishes: billed and paid (Carrefour,
     * Fit Zone), received without a bill (Cairo Optics, Koshary), billed and OUTSTANDING (Nano),
     * and never asked for at all (Al Tazaj, Orange — the shortfall the lease page should show).
     */
    private function seedDeposits(): void
    {
        $bill = app(BillSecurityDepositService::class);
        $depositsBank = BankAccount::defaultFor($this->asset->id, BankAccount::PURPOSE_DEPOSITS)?->id;

        foreach (['carrefour', 'fitzone', 'nano'] as $key) {
            $lease = $this->leases[$key];
            $invoice = $bill->bill($lease, ['issue_date' => CarbonImmutable::instance($lease->commencement_date)->toDateString()]);
            $this->depositInvoiceIds[] = $invoice->id;

            if ($key !== 'nano') {
                $this->receive($invoice, (float) $invoice->total, CarbonImmutable::instance($lease->commencement_date)->addDays(2), 'bank_transfer', BankAccount::PURPOSE_DEPOSITS);
            }
        }

        foreach (['optics', 'koshary'] as $key) {
            $lease = $this->leases[$key];
            DepositTransaction::create([
                'lease_id' => $lease->id,
                'type' => 'receipt',
                'amount' => (float) $lease->security_deposit,
                'transaction_date' => CarbonImmutable::instance($lease->commencement_date)->toDateString(),
                'method' => 'bank_transfer',
                'bank_account_id' => $depositsBank,
                'status' => 'recorded',
                'notes' => 'Security deposit received on signing.',
            ]);
        }

        $this->command?->info('   Deposits: 3 billed (1 still outstanding), 2 received directly, 2 never asked for');
    }

    /**
     * Every month from the earliest commencement up to (not including) `$untilExclusive`, through
     * the SAME method the 02:00 job calls — so the history is exactly what the system would have
     * produced had it been running, stubs prorated and all.
     */
    private function seedBillingHistory(CarbonImmutable $untilExclusive): void
    {
        $billing = app(MonthlyBillingService::class);
        $first = collect($this->leases)
            ->filter(fn (Lease $l) => $l->status === 'active')
            ->map(fn (Lease $l) => CarbonImmutable::instance($l->commencement_date)->startOfMonth())
            ->min();

        $created = 0;
        for ($m = $first; $m->lessThan($untilExclusive); $m = $m->addMonth()) {
            $created += (int) ($billing->runForPeriod($m, $this->asset->id)['created'] ?? 0);
        }

        $this->command?->info("   Billing history: {$created} invoices from {$first->format('M Y')} to {$untilExclusive->subMonth()->format('M Y')}");
    }

    /** This month's run, NOT silenced — the portal bells and the mail path are part of the test. */
    private function seedCurrentMonthBilling(): void
    {
        $stats = app(MonthlyBillingService::class)->runForPeriod($this->today->startOfMonth(), $this->asset->id);
        $owners = app(BillUnitOwnershipsService::class)->runForPeriod($this->today->startOfMonth(), $this->asset->id);

        $this->command?->info('   Current month: '.($stats['created'] ?? 0).' lease invoices + '.($owners['created'] ?? 0).' owner assessments issued (notifications live)');
    }

    /**
     * Who paid what. One rule per tenant, applied to the invoices the billing runs produced —
     * receipts through the same shape `CreatePayment` writes (a captured payment, an allocation
     * on the pivot, totals DERIVED by `recomputeTotals()`).
     */
    private function settleHistory(): void
    {
        $thisMonth = $this->today->startOfMonth();
        $lastMonth = $thisMonth->subMonth();

        /** @var array<string, callable(Invoice): float> share of the invoice that was paid */
        $rules = [
            'carrefour' => fn (Invoice $i) => $this->periodOf($i)->gte($thisMonth) ? 0.0 : 1.0,   // this month: cheque pending
            'tazaj' => fn (Invoice $i) => $this->periodOf($i)->gte($lastMonth) ? 0.0 : 1.0,      // two months behind
            'optics' => fn () => 1.0,                                                            // pays on the nail
            'nano' => fn (Invoice $i) => $this->periodOf($i)->gte($thisMonth) ? 0.0 : 1.0,
            'fitzone' => fn (Invoice $i) => match (true) {
                $this->periodOf($i)->gte($thisMonth) => 0.0,
                $this->periodOf($i)->eq($lastMonth) => 0.5,                                      // partially paid
                default => 1.0,
            },
            'koshary' => fn (Invoice $i) => $this->periodOf($i)->gte($thisMonth) ? 0.0 : 1.0,   // this month: cheque D+3
            'orange' => fn () => 0.0,                                                            // first invoice, unpaid
        ];

        $receipts = 0;
        foreach ($rules as $key => $rule) {
            $invoices = Invoice::query()
                ->where('lease_id', $this->leases[$key]->id)
                ->whereNotIn('id', $this->depositInvoiceIds)
                // A locked percentage-rent overage is billed as its own invoice, dated today and
                // due in a week — live AR that should go overdue on its own, not be settled here.
                ->whereDoesntHave('items', fn ($q) => $q->where('type', 'percentage_rent'))
                ->where('balance', '>', 0)
                ->orderBy('period_start')
                ->get();

            foreach ($invoices as $invoice) {
                $share = (float) $rule($invoice);
                if ($share <= 0) {
                    continue;
                }
                $amount = round((float) $invoice->total * $share, 2);
                $on = CarbonImmutable::instance($invoice->due_date)->subDays(2);
                $this->receive($invoice, $amount, $on->lessThan($this->today) ? $on : $this->today, $receipts % 4 === 3 ? 'instapay' : 'bank_transfer');
                $receipts++;
            }
        }

        // Unit owners: Hassan pays every assessment; Layla stopped two months ago.
        foreach (Invoice::query()->whereNotNull('unit_ownership_id')->where('asset_id', $this->asset->id)->where('balance', '>', 0)->orderBy('period_start')->get() as $invoice) {
            $owner = $invoice->tenant;
            if (str_contains((string) $owner?->email, 'layla') && $this->periodOf($invoice)->gte($lastMonth)) {
                continue;
            }
            $on = CarbonImmutable::instance($invoice->due_date)->subDay();
            $this->receive($invoice, (float) $invoice->total, $on->lessThan($this->today) ? $on : $this->today, 'bank_transfer');
            $receipts++;
        }

        $this->command?->info("   Receipts: {$receipts} (Al Tazaj two months behind · Fit Zone half-paid last month · Orange unpaid · Layla's assessments unpaid)");
    }

    private function periodOf(Invoice $invoice): CarbonImmutable
    {
        return CarbonImmutable::instance($invoice->period_start)->startOfMonth();
    }

    /**
     * The receipt and its allocation are ONE unit of work, exactly as `CreatePayment` writes them.
     * The ledger job is dispatched after COMMIT; created outside a transaction the payment would be
     * posted before its allocation existed (no invoice → no property → an unearned-revenue entry
     * with no asset), then voided and re-posted a moment later. Fifty-three void entries on a
     * fresh set of books is noise that reads as a defect.
     */
    private function receive(Invoice $invoice, float $amount, CarbonImmutable $on, string $method = 'bank_transfer', string $purpose = BankAccount::PURPOSE_OPERATING): Payment
    {
        $amount = round(min($amount, (float) $invoice->balance), 2);

        return DB::transaction(function () use ($invoice, $amount, $on, $method, $purpose): Payment {
            $payment = Payment::create([
                'tenant_id' => $invoice->tenant_id,
                'amount' => $amount,
                'currency' => 'EGP',
                'method' => $method,
                'bank_account_id' => $method === 'cash' ? null : BankAccount::defaultFor($this->asset->id, $purpose)?->id,
                'status' => 'captured',
                'payment_date' => $on->toDateString(),
            ]);

            $invoice->payments()->attach($payment->id, ['allocated_amount' => $amount]);
            $invoice->recomputeTotals();

            return $payment;
        });
    }

    /**
     * A back-dated billing run dates every invoice's DUE date from the day the run happens — by
     * design, so a catch-up run after a failed billing night cannot make a tenant overdue on a
     * document they only just received. Seeded history is different: these runs stand in for the
     * nights that never happened, so each invoice is due `terms` days after its ISSUE date, which is
     * what the system would have written on the night. Then the status is re-derived, so last
     * month's unpaid invoices are overdue on D0 rather than on D+8.
     */
    private function restoreHistoricDueDates(): void
    {
        $moved = 0;

        foreach (Invoice::query()->where('asset_id', $this->asset->id)->whereDate('issue_date', '<', $this->today)->get() as $invoice) {
            $issue = CarbonImmutable::instance($invoice->issue_date)->startOfDay();
            $due = CarbonImmutable::instance($invoice->due_date)->startOfDay();

            // Only a due date the run pushed to TODAY + terms is re-anchored. One already in the
            // past was dated from its own issue date (a deposit bill through IssueInvoiceService,
            // for one) and is left exactly as the service wrote it.
            if ($due->lessThan($this->today)) {
                continue;
            }

            $terms = (int) $this->today->diffInDays($due, false);

            if ($due->lessThanOrEqualTo($issue->addDays($terms))) {
                continue;
            }

            $invoice->update(['due_date' => $issue->addDays($terms)->toDateString()]);
            $invoice->recomputeTotals();
            $moved++;
        }

        $this->command?->info("   Due dates: {$moved} back-dated invoices re-anchored to issue date + terms");
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Cheques, options, sales
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function seedPostDatedCheques(): void
    {
        $service = app(PostDatedChequeService::class);
        $thisMonth = $this->today->startOfMonth();

        // Carrefour: a series of ten monthly cheques for this month's invoice amount, the first
        // maturing D+5. Ten, not twelve — the coverage scan should say they run out before the
        // lease does; and the rent steps +7% on D+10, so from next month each cheque under-covers.
        $carrefourInvoice = Invoice::query()->where('lease_id', $this->leases['carrefour']->id)
            ->whereDate('period_start', $thisMonth->toDateString())->first();
        $series = $service->lodgeSeries([
            'asset_id' => $this->asset->id,
            'tenant_id' => $this->tenants['carrefour']->id,
            'lease_id' => $this->leases['carrefour']->id,
            'bank_name' => 'CIB',
            'first_cheque_number' => '400201',
            'amount' => (float) ($carrefourInvoice?->total ?? 111600),
            'count' => 10,
            'interval_months' => 1,
            'first_cheque_date' => $this->today->addDays(5)->toDateString(),
            'received_date' => $this->today->subDays(3)->toDateString(),
            'notes' => 'Annual cheque book handed over at renewal review — ten cheques only.',
        ]);

        // Koshary: this month's invoice is covered by a cheque maturing D+3 (held — somebody has to
        // bank it), and next month's by one maturing D+33.
        $koshary = $this->leases['koshary'];
        $kosharyInvoice = Invoice::query()->where('lease_id', $koshary->id)
            ->whereDate('period_start', $thisMonth->toDateString())->first();
        foreach ([[3, $kosharyInvoice], [33, null]] as [$days, $invoice]) {
            PostDatedCheque::create([
                'reference' => PostDatedCheque::generateReference(),
                'asset_id' => $this->asset->id,
                'tenant_id' => $koshary->tenant_id,
                'lease_id' => $koshary->id,
                'invoice_id' => $invoice?->id,
                'cheque_number' => 'KT-'.(7701 + $days),
                'bank_name' => 'Banque Misr',
                'amount' => (float) ($kosharyInvoice?->total ?? 42980),
                'currency' => 'EGP',
                'cheque_date' => $this->today->addDays($days)->toDateString(),
                'received_date' => $this->today->subDays(2)->toDateString(),
                'status' => PostDatedCheque::STATUS_HELD,
            ]);
        }

        $this->command?->info('   Post-dated cheques: Carrefour series of '.$series->count().' from D+5 · Koshary D+3 (against this month) and D+33');
    }

    private function seedLeaseOptions(): void
    {
        $fitzone = $this->leases['fitzone'];

        LeaseOption::create([
            'lease_id' => $fitzone->id,
            'type' => 'renewal',
            'status' => 'open',
            'earliest_notice_date' => $this->today->subDays(4)->toDateString(),
            'latest_notice_date' => $this->today->addDays(20)->toDateString(),
            'term_months' => 36,
            'rent_basis' => 'uplift_percent',
            'uplift_percent' => 8.00,
            'notes' => 'One three-year renewal at 8% over the passing rent. Notice window closes D+20.',
        ]);

        LeaseOption::create([
            'lease_id' => $fitzone->id,
            'type' => 'expansion',
            'status' => 'open',
            'earliest_notice_date' => $this->today->addDays(7)->toDateString(),
            'latest_notice_date' => $this->today->addDays(60)->toDateString(),
            'unit_id' => $this->unit('B-05')->id,
            'rent_basis' => 'market',
            'notes' => 'First right over B-05 next door. Window opens D+7.',
        ]);

        $this->command?->info('   Lease options: Fit Zone renewal (closes D+20) + expansion over B-05 (opens D+7)');
    }

    /**
     * The two percentage-rent tenants declared and were locked for the two months before last,
     * and NEVER declared last month — the gap the 10th and 17th scans exist for.
     */
    private function seedSalesDeclarations(): void
    {
        $service = app(PercentageRentCalculationService::class);
        $thisMonth = $this->today->startOfMonth();
        $sales = [
            'carrefour' => [3 => 1650000, 2 => 1720000],
            'tazaj' => [3 => 380000, 2 => 450000],
        ];
        $locked = 0;

        foreach ($sales as $key => $months) {
            $lease = $this->leases[$key];
            foreach ($months as $back => $figure) {
                $periodStart = $thisMonth->subMonths($back);

                $declaration = TenantSalesDeclaration::create([
                    'lease_id' => $lease->id,
                    'period_start' => $periodStart->toDateString(),
                    'period_end' => $periodStart->endOfMonth()->toDateString(),
                    'declared_sales' => $figure,
                    'declared_at' => $periodStart->endOfMonth()->addDays(3),
                    'declared_by_type' => MorphMap::alias(Tenant::class),
                    'declared_by_id' => $lease->tenant_id,
                    'status' => 'submitted',
                ]);

                $service->recalculate($declaration);

                if ($this->admin !== null) {
                    $service->lock($declaration->refresh(), $this->admin, 'Reviewed against the POS report.');
                    $locked++;
                }
            }
        }

        $this->command?->info("   Sales declarations: {$locked} locked (two months each) · LAST month undeclared for both");
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Unit owners
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function seedOwners(): void
    {
        $from = $this->today->startOfMonth()->subMonths(5);
        $buyers = [
            'hassan' => ['name' => 'Hassan Mahmoud', 'national_id' => '28105123400567', 'unit' => 'B-03', 'mode' => UnitManagementMode::SelfOccupied],
            'layla' => ['name' => 'Layla Farouk',   'national_id' => '28809301200321', 'unit' => 'A-02', 'mode' => UnitManagementMode::SelfLet],
        ];

        foreach ($buyers as $key => $b) {
            $owner = Tenant::create([
                'name' => $b['name'],
                'legal_name' => $b['name'],
                'type' => 'individual',
                'party_type' => PartyType::UnitOwner->value,
                'national_id' => $b['national_id'],
                'email' => $key.'@'.self::EMAIL_DOMAIN,
                'phone' => '+2012'.str_pad((string) (55000000 + strlen($key)), 8, '0', STR_PAD_LEFT),
                'address' => 'New Cairo',
                'address_governorate' => 'Cairo',
                'status' => 'active',
            ]);
            $unit = $this->unit($b['unit']);

            $ownership = UnitOwnership::create([
                'asset_id' => $this->asset->id,
                'unit_id' => $unit->id,
                'tenant_id' => $owner->id,
                'status' => UnitOwnershipStatus::HandedOver->value,
                'tenure_type' => UnitTenureType::Freehold->value,
                'management_mode' => $b['mode']->value,
                'started_at' => $from->toDateString(),
                'handover_date' => $from->toDateString(),
                'purchase_date' => $from->subMonths(2)->toDateString(),
                'purchase_contract_number' => 'SALE-NG-'.strtoupper(substr(md5($key), 0, 6)),
                'purchase_price' => round((float) $unit->area_sqm * 48000, 2),
                'payment_terms_days' => 14,
                'notes' => 'Sold '.$from->format('M Y').'.',
            ]);

            Charge::create([
                'unit_ownership_id' => $ownership->id,
                'name' => 'Service charge',
                'type' => 'service_charge',
                'amount' => round((float) $unit->area_sqm * 55, 2),
                'currency' => 'EGP',
                'frequency' => 'monthly',
                'is_active' => true,
                'start_date' => $from->toDateString(),
            ]);

            $this->tenants[$key] = $owner;
        }

        // Every month they have owned it, up to LAST month — this month is raised with the live run.
        $run = app(BillUnitOwnershipsService::class);
        $assessments = 0;
        for ($m = $from; $m->lessThan($this->today->startOfMonth()); $m = $m->addMonth()) {
            $assessments += (int) ($run->runForPeriod($m, $this->asset->id)['created'] ?? 0);
        }

        $this->command?->info("   Unit owners: 2 handed over {$from->format('M Y')}, {$assessments} assessments billed");
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Payables
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function seedVendors(): void
    {
        $yearStart = $this->today->startOfYear();
        $yearEnd = $this->today->endOfYear();

        $rows = [
            'nileclean' => ['name' => 'Nile Clean Services', 'type' => 'service_provider', 'email' => 'ops@nileclean.eg', 'tax_id' => 'EG-512-100-001', 'contact' => ['Mohamed Ezzat', 'Operations Manager'], 'coi_days' => 240,
                'contract' => ['name' => 'Daily cleaning retainer', 'value' => 300000, 'start' => $yearStart, 'end' => $yearEnd, 'notice' => 60, 'auto' => true]],
            // Ends on D+7 with a 30-day notice period → the notice deadline passed 23 days ago.
            'guardian' => ['name' => 'Guardian Security', 'type' => 'service_provider', 'email' => 'ops@guardian-security.eg', 'tax_id' => 'EG-512-100-002', 'contact' => ['Sameh Lotfy', 'Site Supervisor'], 'coi_days' => 240,
                'contract' => ['name' => 'Mall security — 24/7', 'value' => 720000, 'start' => $this->today->addDays(7)->subYear()->addDay(), 'end' => $this->today->addDays(7), 'notice' => 30, 'auto' => false]],
            // Insurance certificate lapses on D+20 → inside the 30-day chase window from D+1.
            'delta' => ['name' => 'Delta Elevators', 'type' => 'contractor', 'email' => 'service@delta-elevators.eg', 'tax_id' => 'EG-512-100-003', 'contact' => ['Eng. Wael Nabil', 'Service Manager'], 'coi_days' => 20,
                'contract' => ['name' => 'Lift & escalator maintenance', 'value' => 180000, 'start' => $yearStart, 'end' => $yearEnd, 'notice' => 30, 'auto' => false]],
        ];

        foreach ($rows as $key => $v) {
            $vendor = Vendor::create([
                'name' => $v['name'],
                'type' => $v['type'],
                'status' => 'active',
                'tax_id' => $v['tax_id'],
                'email' => $v['email'],
                'phone' => '+2010'.str_pad((string) (66000000 + strlen($key)), 8, '0', STR_PAD_LEFT),
                'city' => 'Cairo',
            ]);

            VendorDocument::create([
                'vendor_id' => $vendor->id,
                'type' => VendorDocument::TYPE_INSURANCE_COI,
                'reference' => 'POL-'.strtoupper(substr(md5($v['email']), 0, 8)),
                'issuer' => 'Misr Insurance',
                'expires_on' => $this->today->addDays($v['coi_days'])->toDateString(),
            ]);
            VendorDocument::create([
                'vendor_id' => $vendor->id,
                'type' => VendorDocument::TYPE_TAX_CARD,
                'reference' => $v['tax_id'],
                'issuer' => 'ETA',
                'expires_on' => $this->today->addDays(400)->toDateString(),
            ]);
            VendorDocument::create([
                'vendor_id' => $vendor->id,
                'type' => VendorDocument::TYPE_COMMERCIAL_REGISTER,
                'reference' => 'CR-'.strtoupper(substr(md5($v['email']), 0, 6)),
                'expires_on' => $this->today->addMonths(20)->toDateString(),
            ]);

            VendorContact::create([
                'vendor_id' => $vendor->id,
                'name' => $v['contact'][0],
                'role' => $v['contact'][1],
                'email' => $v['email'],
                'phone' => '+2010'.str_pad((string) (66000000 + strlen($key)), 8, '0', STR_PAD_LEFT),
                'is_primary' => true,
                'is_portal_user' => true,
                'password' => $this->password,
            ]);

            $c = $v['contract'];
            $this->contracts[$key] = VendorContract::create([
                'vendor_id' => $vendor->id,
                'asset_id' => $this->asset->id,
                'name' => $c['name'],
                'status' => 'active',
                'start_date' => $c['start']->toDateString(),
                'end_date' => $c['end']->toDateString(),
                'notice_period_days' => $c['notice'],
                'auto_renews' => $c['auto'],
                'value' => $c['value'],
                'currency' => 'EGP',
                'sla_penalty_basis' => 'none',
                'sla_penalty_rate' => 0,
            ]);

            $this->vendors[$key] = $vendor;
        }

        $this->command?->info('   Vendors: 3 with contracts (Guardian ends D+7, notice overdue) and portal contacts');
    }

    private function seedVendorBillsAndExpenses(): void
    {
        $svc = app(VendorBillService::class);
        $bank = BankAccount::defaultFor($this->asset->id, BankAccount::PURPOSE_OPERATING)?->id;
        $thisMonth = $this->today->startOfMonth();

        // Nile Clean: the three previous months' retainers, approved and paid.
        foreach ([3, 2, 1] as $back) {
            $billDate = $thisMonth->subMonths($back)->day(7);
            $bill = $this->vendorBill('nileclean', $billDate, 'NC-'.$billDate->format('Y-m'), 'cleaning_security', 25000, 'Cleaning retainer — '.$billDate->format('F Y'));
            $svc->approve($bill);
            $svc->recordPayment($bill, (float) $bill->total, 'bank_transfer', $billDate->addDays(20), null, $bank);
        }

        // Guardian: last month's retainer approved and still UNPAID — open AP, due D+10.
        $guardianDate = $this->today->addDays(10)->subDays(30);
        $svc->approve($this->vendorBill('guardian', $guardianDate, 'GS-'.$guardianDate->format('Y-m'), 'cleaning_security', 60000, 'Security retainer — '.$guardianDate->format('F Y')));

        // Delta: a call-out from last week, still a draft awaiting approval.
        $this->vendorBill('delta', $this->today->subDays(6), 'DE-'.$this->today->subDays(6)->format('ymd'), 'maintenance', 8500, 'Emergency call-out — lift 2 door sensor');

        $samples = [
            ['category' => 'utilities',   'desc' => 'Common-area electricity',      'amount' => 12400, 'paid' => 'bank_transfer', 'days' => 40, 'vat' => true],
            ['category' => 'admin',       'desc' => 'Stationery and printing',      'amount' => 900,   'paid' => 'cash',          'days' => 25, 'vat' => true],
            ['category' => 'maintenance', 'desc' => 'Replacement door closer, A-03', 'amount' => 1800,  'paid' => 'cash',          'days' => 12, 'vat' => false],
        ];
        foreach ($samples as $s) {
            $vat = $s['vat'] ? round($s['amount'] * 0.14, 2) : 0.0;
            Expense::create([
                'asset_id' => $this->asset->id,
                'category' => $s['category'],
                'description' => $s['desc'],
                'amount' => $s['amount'],
                'vat_amount' => $vat,
                'tax_code' => $s['vat'] ? 'VAT_14_P' : null,
                'total' => $s['amount'] + $vat,
                'paid_from' => $s['paid'],
                'bank_account_id' => $s['paid'] === 'cash' ? null : $bank,
                'expense_date' => $this->today->subDays($s['days'])->toDateString(),
                'status' => 'recorded',
            ]);
        }

        $this->command?->info('   Payables: 3 paid retainers, 1 approved unpaid (Guardian, due D+10), 1 draft call-out; 3 direct expenses');
    }

    private function vendorBill(string $vendorKey, CarbonImmutable $billDate, string $reference, string $category, float $subtotal, string $description): VendorBill
    {
        $vat = round($subtotal * 0.14, 2);

        return VendorBill::create([
            'vendor_id' => $this->vendors[$vendorKey]->id,
            'vendor_contract_id' => $this->contracts[$vendorKey]->id ?? null,
            'asset_id' => $this->asset->id,
            'category' => $category,
            'bill_date' => $billDate->toDateString(),
            'due_date' => $billDate->addDays(30)->toDateString(),
            'reference' => $reference,
            'description' => $description,
            'subtotal' => $subtotal,
            'vat_amount' => $vat,
            'tax_code' => 'VAT_14_P',
            'total' => round($subtotal + $vat, 2),
            'status' => 'draft',
        ]);
    }

    /**
     * The costs that arrive on a calendar. `last_generated_on` is set to LAST month's occurrence so
     * the first thing the daily generator raises is THIS month's — on its own day, not a backlog.
     */
    private function seedRecurringExpenses(): void
    {
        $bank = BankAccount::defaultFor($this->asset->id, BankAccount::PURPOSE_OPERATING)?->id;
        $thisMonth = $this->today->startOfMonth();

        $rows = [
            ['vendor' => 'nileclean', 'desc' => 'Cleaning retainer',        'category' => 'cleaning_security', 'amount' => 25000, 'day' => 7,  'terms' => 30],
            ['vendor' => 'guardian',  'desc' => 'Security retainer',        'category' => 'cleaning_security', 'amount' => 60000, 'day' => 20, 'terms' => 30],
            ['vendor' => null,        'desc' => 'Municipal waste levy',     'category' => 'utilities',         'amount' => 4500,  'day' => 15, 'terms' => 0],
            ['vendor' => null,        'desc' => 'Internet & telecom lines', 'category' => 'admin',             'amount' => 2200,  'day' => 25, 'terms' => 0],
        ];

        foreach ($rows as $r) {
            $lastOccurrence = $thisMonth->subMonth()->day($r['day']);
            RecurringExpense::create([
                'asset_id' => $this->asset->id,
                'vendor_id' => $r['vendor'] ? $this->vendors[$r['vendor']]->id : null,
                'vendor_contract_id' => $r['vendor'] ? ($this->contracts[$r['vendor']]->id ?? null) : null,
                'description' => $r['desc'],
                'category' => $r['category'],
                'amount' => $r['amount'],
                'tax_code' => 'VAT_14_P',
                'paid_from' => 'bank_transfer',
                'bank_account_id' => $bank,
                'frequency' => 'monthly',
                'day_of_month' => $r['day'],
                'payment_terms_days' => $r['terms'],
                'starts_on' => $thisMonth->subMonths(3)->toDateString(),
                'last_generated_on' => $lastOccurrence->lessThan($this->today) ? $lastOccurrence->toDateString() : $lastOccurrence->subMonth()->toDateString(),
                'is_active' => true,
            ]);
        }

        $this->command?->info('   Recurring costs: cleaning (7th, vendor → draft bill) · waste levy (15th) · security (20th, contract ends D+7) · telecom (25th)');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Fixed assets, payroll
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function seedFixedAssets(): void
    {
        $thisMonth = $this->today->startOfMonth();
        $register = [
            ['name' => 'Central chiller unit',        'tag' => 'NG-FA-HVAC-01', 'category' => 'HVAC', 'cost' => 360000, 'salvage' => 20000, 'life' => 120, 'months_ago' => 5],
            ['name' => 'CCTV + access-control system', 'tag' => 'NG-FA-SEC-01',  'category' => 'IT',   'cost' => 120000, 'salvage' => 5000,  'life' => 60,  'months_ago' => 2],
        ];

        $earliest = $thisMonth;
        foreach ($register as $r) {
            $acq = $thisMonth->subMonths($r['months_ago']);
            $earliest = $acq->lessThan($earliest) ? $acq : $earliest;
            FixedAsset::create([
                'asset_id' => $this->asset->id,
                'name' => $r['name'],
                'tag' => $r['tag'],
                'category' => $r['category'],
                'acquisition_date' => $acq->toDateString(),
                'acquisition_cost' => $r['cost'],
                'salvage_value' => $r['salvage'],
                'useful_life_months' => $r['life'],
                'method' => 'straight_line',
                'funded_from' => 'bank',
                'status' => 'active',
            ]);
        }

        // Depreciation up to LAST month. This month's charge is the 28th's job.
        $depr = app(DepreciationService::class);
        $posted = 0;
        for ($m = $earliest; $m->lessThan($thisMonth); $m = $m->addMonth()) {
            $posted += $depr->run($m, [$this->asset->id]);
        }

        $this->command?->info("   Fixed assets: 2, {$posted} monthly depreciation entries posted (this month's is due on the 28th)");
    }

    private function seedPayroll(): void
    {
        $depts = Department::query()->pluck('id', 'slug');
        $roster = [
            ['name' => 'Ahmed Sobhy',   'position' => 'Facilities Supervisor',  'dept' => 'operations', 'salary' => 11000, 'pay' => 'bank'],
            ['name' => 'Mina Gerges',   'position' => 'Maintenance Technician', 'dept' => 'operations', 'salary' => 6500,  'pay' => 'cash'],
            ['name' => 'Ramy Shaker',   'position' => 'Security Shift Lead',    'dept' => 'operations', 'salary' => 5800,  'pay' => 'cash'],
            ['name' => 'Salma Hegazy',  'position' => 'Accountant',             'dept' => 'accounting', 'salary' => 12000, 'pay' => 'bank'],
        ];

        foreach ($roster as $i => $r) {
            Employee::create([
                'asset_id' => $this->asset->id,
                'department_id' => $depts[$r['dept']] ?? null,
                'code' => sprintf('NG-EMP-%02d', $i + 1),
                'name' => $r['name'],
                'national_id' => '29'.str_pad((string) (100000000000 + $i * 7919), 12, '0', STR_PAD_LEFT),
                'position' => $r['position'],
                'hire_date' => $this->today->startOfMonth()->subMonths(6 + $i)->toDateString(),
                'base_salary' => $r['salary'],
                'payment_method' => $r['pay'],
                'phone' => '+2011'.str_pad((string) (77000000 + $i), 8, '0', STR_PAD_LEFT),
                'locale' => $i % 2 === 0 ? 'ar' : null,
                'status' => 'active',
            ]);
        }

        // Last month's run, generated through the service and approved (a GL source). This month's
        // run is an act somebody performs during the soak.
        $month = $this->today->startOfMonth()->subMonth();
        $run = Payroll::create([
            'number' => Payroll::generateNumber(self::CODE, $month),
            'asset_id' => $this->asset->id,
            'period_month' => $month->toDateString(),
            'description' => 'Monthly payroll — '.$month->format('F Y'),
            'paid_from' => 'bank',
            'bank_account_id' => BankAccount::defaultFor($this->asset->id, BankAccount::PURPOSE_PAYROLL)?->id,
            'status' => 'draft',
            'gross_salaries' => 0,
            'salary_tax' => 0,
            'social_insurance' => 0,
            'net_paid' => 0,
        ]);
        $generated = app(GeneratePayrollService::class)->generate($run);

        // NOT approved. The shipped payroll-rates rung carries the insurable band with every rate at
        // ZERO (EG-03: the software must not decide to deduct from people's pay), and approving a run
        // that withholds nothing trips `ConfigurationHealth::payrollRatesConfigured()` — a BLOCKING
        // row that fails the next preflight. Setting the rates and approving the run is the
        // operator's act, and one of the soak month's steps.
        $this->command?->info('   HR: '.count($roster).' employees; '.$month->format('F').' payroll generated ('.$generated['added'].' lines) — left in DRAFT until the statutory rates are set');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────
    // Facility, requests, comms
    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function seedServicePlans(): void
    {
        // The operator's cost base, only where nobody has priced the trade yet.
        foreach (['cleaning' => 90, 'generator' => 240, 'fire-safety' => 220, 'hvac' => 200, 'elevator' => 250] as $code => $rate) {
            Trade::query()->where('code', $code)->whereNull('standard_hourly_rate')->update(['standard_hourly_rate' => $rate]);
        }

        $trades = Trade::query()->pluck('id', 'code');
        $ops = Department::query()->where('slug', 'operations')->value('id');

        $plans = [
            ['title' => 'Weekly cleaning & common-area inspection', 'trade' => 'cleaning',    'unit' => 'weeks',  'freq' => 1, 'due' => 2,  'hours' => 2, 'vendor' => 'nileclean',
                'checklist' => ['Walk all corridors and toilets', 'Check waste rooms', 'Inspect entrance mats and glass', 'Log any damage']],
            ['title' => 'Generator monthly test-run',               'trade' => 'generator',   'unit' => 'months', 'freq' => 1, 'due' => 7,  'hours' => 2, 'vendor' => null,
                'checklist' => ['Check fuel & oil levels', 'Run under load 15 min', 'Inspect battery', 'Log readings']],
            ['title' => 'Quarterly fire-safety inspection',         'trade' => 'fire-safety', 'unit' => 'months', 'freq' => 3, 'due' => 15, 'hours' => 4, 'vendor' => null,
                'checklist' => ['Inspect extinguishers', 'Test alarm panel', 'Check emergency exits', 'Verify signage & lighting']],
        ];

        foreach ($plans as $p) {
            ServicePlan::create([
                'asset_id' => $this->asset->id,
                'title' => $p['title'],
                'trade_id' => $trades[$p['trade']] ?? null,
                'plan_type' => ServicePlan::MAINTENANCE_TYPE_ROUTINE,
                'description' => $p['title'].' — preventive schedule.',
                'frequency_unit' => $p['unit'],
                'frequency_value' => $p['freq'],
                'checklist' => $p['checklist'],
                'est_labour_hours' => $p['hours'],
                'department_id' => $ops,
                'vendor_id' => $p['vendor'] ? $this->vendors[$p['vendor']]->id : null,
                'next_due_date' => $this->today->addDays($p['due'])->toDateString(),
                'is_active' => true,
            ]);
        }

        $this->command?->info('   Service plans: weekly cleaning (due D+2) · generator (D+7) · fire safety (D+15)');
    }

    private function seedTenantRequests(): void
    {
        $service = app(TenantRequestService::class);
        $manager = User::query()->where('email', 'manager@mall.test')->first() ?? $this->admin;

        $urgent = $service->create([
            'unit_id' => $this->leases['tazaj']->unit_id,
            'request_type' => 'maintenance',
            'priority' => 'urgent',
            'category' => 'hvac',
            'title' => 'AC blowing warm air in the dining area',
            'description' => 'Both ceiling units over the dining area have been blowing warm air since this morning. Lunch service is suffering — please treat as urgent.',
        ], $this->tenants['tazaj']);

        app(RaiseCorrectiveWorkOrderService::class)->fromTenantRequest($urgent, [
            'execution_type' => 'internal',
            'priority' => 'urgent',
            'title' => 'A-04 — AC units blowing warm air',
            'description' => 'Raised from the tenant request. Check refrigerant and the roof condenser for A-04.',
            'scheduled_for' => $this->today->toDateString(),
            'assigned_to_user_id' => $manager?->id,
            'trade_id' => Trade::query()->where('code', 'hvac')->value('id'),
        ]);

        $service->create([
            'unit_id' => $this->leases['optics']->unit_id,
            'request_type' => 'complaint',
            'priority' => 'medium',
            'category' => 'noise',
            'title' => 'Loud music from the food court after 10pm',
            'description' => 'The food court plays loud music well past closing and our evening customers complain.',
        ], $this->tenants['optics']);

        $service->create([
            'unit_id' => $this->leases['carrefour']->unit_id,
            'request_type' => 'access',
            'priority' => 'low',
            'category' => 'parking',
            'title' => 'Two extra staff parking permits',
            'description' => 'We hired two night-shift supervisors and need basement permits for them.',
        ], $this->tenants['carrefour']);

        $this->command?->info('   Requests: urgent HVAC (work order raised, SLA running) · noise complaint · parking permits');
    }

    private function seedAnnouncement(): void
    {
        Announcement::create([
            'asset_id' => $this->asset->id,
            'title' => 'Fire drill — Wednesday 11:00',
            'title_ar' => 'تجربة إخلاء — الأربعاء الساعة ١١ صباحًا',
            'body' => 'A full evacuation drill will run on Wednesday at 11:00. Please brief your staff; the alarm will sound for about ten minutes.',
            'body_ar' => 'ستُجرى تجربة إخلاء كاملة يوم الأربعاء الساعة ١١ صباحًا. يُرجى إبلاغ فريقكم؛ سيعمل جرس الإنذار لنحو عشر دقائق.',
            'category' => 'operations',
            'status' => Announcement::STATUS_SCHEDULED,
            'publish_at' => $this->today->addDays(4)->setTime(12, 0),
            'expires_at' => $this->today->addDays(14),
            'is_pinned' => false,
            'created_by' => $this->admin?->id,
        ]);

        $this->command?->info('   Announcement scheduled for D+4 12:00');
    }

    // ─────────────────────────────────────────────────────────────────────────────────────────

    private function report(): void
    {
        $assetId = $this->asset->id;
        $ar = Invoice::query()->where('asset_id', $assetId)->whereIn('status', ['issued', 'partially_paid', 'overdue'])->sum('balance');

        $this->command?->newLine();
        $this->command?->info('✅ '.self::NAME.' ('.self::CODE.') is on the books.');
        $this->command?->table(['What', 'Count'], [
            ['Units', Unit::where('asset_id', $assetId)->count().' ('.Unit::where('asset_id', $assetId)->where('status', 'vacant')->count().' vacant)'],
            ['Leases', Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $assetId))->count().' (1 draft)'],
            ['Unit ownerships', UnitOwnership::where('asset_id', $assetId)->count()],
            ['Invoices', Invoice::where('asset_id', $assetId)->count()],
            ['Open AR', 'EGP '.number_format((float) $ar, 2)],
            ['Receipts', Payment::whereHas('invoices', fn ($q) => $q->where('asset_id', $assetId))->count()],
            ['Post-dated cheques', PostDatedCheque::where('asset_id', $assetId)->count()],
            ['Vendor bills', VendorBill::where('asset_id', $assetId)->count()],
            ['Recurring cost schedules', RecurringExpense::where('asset_id', $assetId)->count()],
            ['Service plans', ServicePlan::where('asset_id', $assetId)->count()],
            ['Journal entries (whole box)', JournalEntry::count()],
        ]);
        $this->command?->line('   Portal logins: <fg=cyan>carrefour@'.self::EMAIL_DOMAIN.'</> / <fg=cyan>tazaj@'.self::EMAIL_DOMAIN.'</> · vendor portal: <fg=cyan>ops@nileclean.eg</> — password <fg=cyan>'.$this->password.'</>');
        $this->command?->line('   D0 = '.$this->today->toDateString().'. The expected-event calendar is in this class\'s docblock and in docs/qa/STAGING-SOAK-2026-09.md.');
        $this->command?->newLine();
    }
}
