<?php

namespace Database\Seeders;

use App\Enums\ManagementFeeBasis;
use App\Enums\PartyType;
use App\Enums\TenantRequestType;
use App\Enums\UnitManagementMode;
use App\Enums\UnitOwnershipStatus;
use App\Enums\UnitTenureType;
use App\Models\AccountingPeriod;
use App\Models\Announcement;
use App\Models\Area;
use App\Models\Asset;
use App\Models\BankAccount;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\CustomField;
use App\Models\Department;
use App\Models\DepositTransaction;
use App\Models\Employee;
use App\Models\Equipment;
use App\Models\Expense;
use App\Models\FacilityWorkOrder;
use App\Models\FacilityWorkOrderItem;
use App\Models\FacilityWorkOrderLabour;
use App\Models\FailureCode;
use App\Models\FixedAsset;
use App\Models\Floor;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\LeaseOption;
use App\Models\LedgerAccount;
use App\Models\MarketingBudget;
use App\Models\MarketingPost;
use App\Models\MarketingSpend;
use App\Models\MeterReading;
use App\Models\Note;
use App\Models\OwnerStatementRun;
use App\Models\Payment;
use App\Models\PaymentMethod;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\PostDatedCheque;
use App\Models\RentableItem;
use App\Models\RentIndex;
use App\Models\ServicePlan;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Models\TenantDocument;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\Trade;
use App\Models\Unit;
use App\Models\UnitOwnership;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Models\VendorContractAmendment;
use App\Models\VendorDocument;
use App\Models\Violation;
use App\Models\ViolationCategory;
use App\Models\Warehouse;
use App\Models\WorkPermit;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\SetPostMonthService;
use App\Services\AllocatePaymentToInvoiceItemsService;
use App\Services\AssignRentableItemService;
use App\Services\BillUnitOwnershipsService;
use App\Services\BillViolationFineService;
use App\Services\CamReconciliationService;
use App\Services\CreditNoteService;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use App\Services\DisputeInvoiceItemService;
use App\Services\FacilityWorkOrderService;
use App\Services\GeneratePreventiveWorkOrdersService;
use App\Services\GrantCustodyService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\LeaseReliefService;
use App\Services\LeaseSpaceChangeService;
use App\Services\OwnerAccounting\FinaliseOwnerStatementRunService;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Services\OwnerRequestService;
use App\Services\PayrollService;
use App\Services\PercentageRentCalculationService;
use App\Services\PostDatedChequeService;
use App\Services\PurchaseRequestService;
use App\Services\RaiseCorrectiveWorkOrderService;
use App\Services\RecordAdvanceRepaymentService;
use App\Services\SendAnnouncementAction;
use App\Services\SettleCustodyService;
use App\Services\StockMovementService;
use App\Services\VendorBillService;
use App\Services\WorkOrderPartService;
use App\Services\WorkOrderProposalService;
use App\Services\WorkPermitService;
use App\Support\MorphMap;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    /**
     * Deterministic seed for PHP's RNG. Locks every `rand()` call below to a
     * reproducible sequence so the demo numbers (occupancy %, AR balance,
     * collected this month, overdue count) stay stable across reseeds and
     * across environments. Absolute dates still float (they're computed
     * from real `now()`), but relative quantities and ratios are pinned.
     */
    public const DEMO_RNG_SEED = 4242;

    /*
    |--------------------------------------------------------------------------
    | Whose mall this is
    |--------------------------------------------------------------------------
    | The demo estate's IDENTITY, in one place, so a seeder for a real prospect
    | is a subclass rather than a fork. `ValPlazaSeeder` is the first of those.
    |
    | It has to be here and not a post-seed rename, because the mall CODE is
    | baked into every document number the run allocates — `LSE-AW-2026-0007`,
    | `INV-AW-0341`. Renaming the asset afterwards leaves every invoice, lease
    | and receipt in the demo carrying the previous mall's initials, which is
    | the first thing a client reads on the page they are shown.
    |
    | Defaults are the existing Atriom Walk values, so `DemoSeeder` seeds exactly
    | what it always did and every test that reads `code = 'AW'` stays green.
    */

    protected function primaryCode(): string
    {
        return 'AW';
    }

    protected function primaryName(): string
    {
        return 'Atriom Walk';
    }

    /** The second property — it exists so property isolation has visible effect. */
    protected function secondaryCode(): string
    {
        return 'PA';
    }

    protected function secondaryName(): string
    {
        return 'Plaza Annex';
    }

    protected function ownerName(): string
    {
        return 'Atriom Developments';
    }

    /** Where the demo's portal logins live. `.test` is reserved and can never resolve. */
    protected function emailDomain(): string
    {
        return 'atriomwalk.test';
    }

    private ?BankAccount $cibAccount = null;

    private ?BankAccount $nbeAccount = null;

    private ?BankAccount $depositAccount = null;

    public function run(): void
    {
        mt_srand(self::DEMO_RNG_SEED);

        $this->command->info('🏬 Seeding '.$this->primaryName().' demo data...');

        // Demo password lives in env so production deploys can rotate
        // without touching the seeder (audit M17 F-63 / D-48). Default
        // 'password' matches DEMO.md for dev + CI. Pre-pilot deploys
        // MUST override DEMO_USER_PASSWORD and trigger a first-login
        // rotation when the URL becomes public.
        $demoPassword = Hash::make((string) config('demo.user_password'));

        // 0. Admin + role-demo users (all share the demo password above)
        $users = [
            ['email' => 'admin@mall.test',       'name' => 'Mall Admin',           'role' => 'super_admin'],
            ['email' => 'manager@mall.test',     'name' => 'Operations Manager',   'role' => 'manager'],
            ['email' => 'viewer@mall.test',      'name' => 'Property Auditor',     'role' => 'viewer'],
            ['email' => 'owner@atriom.test',     'name' => 'Property Owner',       'role' => 'owner'],
            ['email' => 'leasing@mall.test',     'name' => 'Leasing Manager',      'role' => 'leasing'],
            ['email' => 'operations@mall.test',  'name' => 'Operations Lead',      'role' => 'operations'],
            ['email' => 'accounting@mall.test',  'name' => 'Accounting Lead',      'role' => 'accounting'],
            ['email' => 'marketing@mall.test',   'name' => 'Marketing Lead',       'role' => 'marketing'],
            ['email' => 'hr@mall.test',          'name' => 'HR Lead',              'role' => 'hr'],
        ];
        foreach ($users as $u) {
            $user = User::updateOrCreate(
                ['email' => $u['email']],
                ['name' => $u['name'], 'password' => $demoPassword],
            );
            $user->syncRoles([$u['role']]);
        }

        // 1. The Asset
        $atriomWalk = Asset::updateOrCreate(
            ['code' => $this->primaryCode()],
            [
                'name' => $this->primaryName(),
                'type' => 'retail_walk',
                'address' => 'Wahat Road, 6th of October City',
                'city' => '6th of October',
                'country' => 'Egypt',
                'total_area_sqm' => 12000,
                'leasable_area_sqm' => 8500,
                'currency' => 'EGP',
                'metadata' => [
                    'owner' => $this->ownerName(),
                    'launched' => '2025',
                ],
            ],
        );

        // The banking register, before ANY money exists — every receipt and expense below names
        // one of these as it is created. Seeded late, it was invisible: the invoice-history
        // generator and the current-month payment run both fire earlier, so 193 of 194 demo
        // receipts recorded no bank account at all.
        $this->seedBankAccounts($atriomWalk);

        // Attach the owner user to Atriom Walk at 100% ownership
        $ownerUser = User::where('email', 'owner@atriom.test')->first();
        if ($ownerUser) {
            $atriomWalk->propertyOwners()->syncWithoutDetaching([
                $ownerUser->id => [
                    'ownership_percentage' => 100,
                    'started_at' => '2020-01-01',
                ],
            ]);
        }

        // Second small property — Plaza Annex. Exists so property-staff
        // scoping enforcement has visible effect: a staff member assigned only
        // to Atriom Walk should not see Plaza Annex's units/leases/invoices,
        // and vice versa. Lightweight on purpose (8 units, no leases yet) so
        // the demo dataset stays clean.
        $plazaAnnex = Asset::updateOrCreate(
            ['code' => $this->secondaryCode()],
            [
                'name' => $this->secondaryName(),
                'type' => 'retail_walk',
                'address' => 'Plaza Road, 6th of October City',
                'city' => '6th of October',
                'country' => 'Egypt',
                'total_area_sqm' => 2200,
                'leasable_area_sqm' => 1600,
                'currency' => 'EGP',
                'is_active' => true,
                'metadata' => ['owner' => $this->ownerName(), 'launched' => '2026', 'notes' => 'Strip annex; scoping demo asset.'],
            ],
        );
        foreach (range(1, 8) as $n) {
            Unit::updateOrCreate(
                ['asset_id' => $plazaAnnex->id, 'code' => sprintf('PA-%02d', $n)],
                [
                    'floor_id' => $this->floorFor($plazaAnnex, 'Ground')->id,
                    'category' => $n <= 4 ? 'retail' : 'food_beverage',
                    'area_sqm' => 80 + ($n * 5),
                    'status' => 'vacant',
                ],
            );
        }

        // 2. Define unit layout — 50 units across 3 zones (A, B, C)
        $units = $this->unitLayout();

        // 3. Define tenants — community retail walk mix
        $tenants = $this->tenantList();

        $occupiedCount = 0;
        $vacantCount = 0;

        foreach ($units as $i => $unitData) {
            $unit = Unit::create([
                'asset_id' => $atriomWalk->id,
                'code' => $unitData['code'],
                'floor_id' => $this->floorFor($atriomWalk, $unitData['floor'])->id,
                'category' => $unitData['category'],
                'area_sqm' => $unitData['area'],
                'status' => 'vacant', // will flip if leased
            ]);

            // Leave ~15% vacant (7 of 50) for realistic occupancy
            if ($i >= count($tenants)) {
                $vacantCount++;

                continue;
            }

            $tenantData = $tenants[$i];

            // First three tenants get portal-login creds (tenant1/2/3@atriomwalk.test / password)
            $portalEmail = $i < 3
                ? 'tenant'.($i + 1).'@'.$this->emailDomain()
                : ($tenantData['email'] ?? Str::slug($tenantData['name']).'@example.com');

            $tenant = Tenant::create([
                'name' => $tenantData['name'],
                'legal_name' => $tenantData['legal'] ?? $tenantData['name'].' LLC',
                'type' => 'company',
                'email' => $portalEmail,
                'password' => $i < 3 ? $demoPassword : null,
                'phone' => '+201'.rand(100000000, 999999999),
                'whatsapp' => '+201'.rand(100000000, 999999999),
                'tax_id' => (string) rand(100000000, 999999999),
                // The address in the PARTS the tax authority validates — governorate, city,
                // street, building. Seeded because a business tenant's registered address is
                // real master data the tenant record and its documents read; e-invoicing was
                // what first required the breakdown, and it survives the module's freeze.
                'address' => 'Unit '.($i + 1).', '.$this->primaryName().', 6th of October City',
                'address_governorate' => 'Giza',
                'address_city' => '6th of October City',
                'address_street' => 'Wahat Road',
                'address_building_number' => (string) (100 + $i),
                'contact_person' => $tenantData['contact'] ?? 'Owner',
                // The language this retailer's invoices and statements are issued in. Every third
                // tenant reads Arabic, and the rest have stated nothing — which is the real
                // distribution and the one that makes the download picker demonstrably do
                // something. With the column blank everywhere it opens on English for every record
                // and the feature reads as unbuilt, which is what the demo data is for.
                'locale' => $i % 3 === 0 ? 'ar' : null,
                'status' => 'active',
            ]);

            // Compliance paperwork, spread across the three states the chase distinguishes so the
            // screen and `tenants:scan-document-expiry` both have something real to show: current,
            // lapsing inside the 30-day window, and already lapsed. Without this the whole module
            // is invisible in a demo — every tenant would simply have no documents.
            $insuranceExpiry = match ($i % 3) {
                0 => Carbon::now()->addMonths(8),      // comfortably current
                1 => Carbon::now()->addDays(12),       // inside the chase window
                default => Carbon::now()->subDays(9),  // lapsed — trading uninsured
            };

            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => TenantDocument::TYPE_INSURANCE_COI,
                'reference' => 'POL-'.rand(100000, 999999),
                'issuer' => ['Misr Insurance', 'AXA Egypt', 'Allianz Egypt'][$i % 3],
                'issued_on' => $insuranceExpiry->copy()->subYear(),
                'expires_on' => $insuranceExpiry,
                // The sum insured is the number an operator compares against the lease.
                'coverage_amount' => 1000000,
            ]);

            // The statutory pair every Egyptian retailer files, with no renewal date tracked —
            // documents we hold rather than documents we nag about.
            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => TenantDocument::TYPE_TAX_CARD,
                'reference' => $tenant->tax_id,
                'issuer' => 'مصلحة الضرائب المصرية',
            ]);

            TenantDocument::create([
                'tenant_id' => $tenant->id,
                'type' => TenantDocument::TYPE_COMMERCIAL_REGISTER,
                'reference' => (string) rand(10000, 99999),
                'issuer' => 'السجل التجاري',
            ]);

            // Portal logins (req #9 multi-user): the first three tenants get an
            // ADMIN TenantUser; tenant1 also gets a second, NON-admin (read-only)
            // user so the admin-can-write / others-read-only split is demoable.
            if ($i < 3) {
                TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'name' => $tenantData['contact'] ?? 'Tenant Admin',
                    'email' => $portalEmail,
                    'password' => $demoPassword,
                    'is_admin' => true,
                ]);

                if ($i === 0) {
                    TenantUser::create([
                        'tenant_id' => $tenant->id,
                        'name' => 'Tenant Staff (read-only)',
                        'email' => 'staff1@'.$this->emailDomain(),
                        'password' => $demoPassword,
                        'is_admin' => false,
                    ]);
                }
            }

            // Create the lease
            $commencement = Carbon::now()->subMonths(rand(2, 10))->startOfMonth();
            $term = $tenantData['term'] ?? 36;

            // First 4 leases expire within the next 90 days so the ExpiringLeases
            // widget always has realistic demo content (15d / 35d / 65d / 80d).
            $expiringSoonDays = [0 => 15, 1 => 35, 2 => 65, 3 => 80];
            $expiry = isset($expiringSoonDays[$i])
                ? Carbon::now()->startOfDay()->addDays($expiringSoonDays[$i])
                : $commencement->copy()->addMonths($term);

            $rent = $unitData['base_rent'] ?? $this->calculateRent($unitData);
            $service = round($rent * 0.15, 0); // service charge ~15% of rent

            $lease = Lease::create([
                'reference' => Lease::generateReference($this->primaryCode()),
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'status' => 'active',
                'commencement_date' => $commencement,
                'expiry_date' => $expiry,
                'term_months' => $term,
                'base_rent_monthly' => $rent,
                'service_charge_monthly' => $service,
                'currency' => 'EGP',
                'security_deposit' => $rent * 3,
                // A spread of escalation shapes rather than seven identical 7% leases, so the
                // demo shows what the module can express and the E2E smoke walks each branch.
                // Deterministic by index — a random spread makes a failing demo unreproducible.
                'escalation_rate' => $i % 3 === 2 ? 0 : 7.00,
                'escalation_type' => $i % 3 === 2 ? 'fixed_amount' : 'fixed_percent',
                // The anchor deal: a flat step in pounds, which is what a large tenant negotiates.
                'escalation_amount' => $i % 3 === 2 ? round($rent * 0.05, 0) : null,
                // The collar, on the CPI-ish leases: "no less than 3%, no more than 10%".
                'escalation_floor_rate' => $i % 3 === 0 ? 3.00 : null,
                'escalation_ceiling_rate' => $i % 3 === 0 ? 10.00 : null,
                'next_escalation_date' => $commencement->copy()->addYear(),
                // Handover a fortnight before the term starts, and — on every fourth lease — a
                // three-month fit-out before rent begins. Leaving rent-commencement null on the
                // rest keeps most of the book billing from commencement, which is the normal case.
                'possession_date' => $commencement->copy()->subDays(14),
                'rent_commencement_date' => $i % 4 === 3
                    ? $commencement->copy()->startOfMonth()->addMonths(3)
                    : null,
                'fit_out_scope' => Lease::FIT_OUT_RENT_ONLY,
                'has_percentage_rent' => in_array($unitData['category'], ['retail', 'food_beverage']),
                'percentage_rent_threshold' => in_array($unitData['category'], ['retail', 'food_beverage']) ? $rent * 5 : null,
                'percentage_rent_rate' => in_array($unitData['category'], ['retail', 'food_beverage']) ? 6.00 : null,
                'percentage_rent_calculation_type' => in_array($unitData['category'], ['retail', 'food_beverage']) ? 'artificial' : null,
                'payment_terms_days' => 7,
            ]);

            // Update unit status
            $unit->update(['status' => 'occupied']);
            $occupiedCount++;

            // Create charges — dated from the commencement, exactly as
            // `LeaseCreationService::seedStandardCharges()` does. Leaving `start_date` null produced
            // demo data no part of the app would ever write: `atriom:audit-charge-schedules` flagged
            // 66 undated rows on a FRESH install, because the LS-06 stamping migration runs before
            // the seeder and so could never reach them.
            Charge::create([
                'lease_id' => $lease->id,
                'name' => 'Base Rent',
                'type' => 'base_rent',
                'amount' => $rent,
                'frequency' => 'monthly',
                'vat_applicable' => false, // rent is VAT-exempt in Egypt
                'vat_rate' => Vat::EXEMPT,
                'start_date' => $commencement,
                'is_active' => true,
            ]);

            Charge::create([
                'lease_id' => $lease->id,
                'name' => 'Service Charge',
                'type' => 'service_charge',
                'amount' => $service,
                'frequency' => 'monthly',
                'vat_applicable' => true,
                // Settings-driven, never a literal — the same rule the app is gated on.
                'vat_rate' => Vat::standardRate(),
                'start_date' => $commencement,
                'is_active' => true,
            ]);

            // Generate past invoices (for AR aging realism)
            $this->generateInvoiceHistory($lease, $tenant, $rent, $service, $commencement);
        }

        // Showcase a MULTI-UNIT lease (#7 master unit): expand one active lease
        // to also cover an adjacent vacant unit, keeping the original as master.
        $multiLease = Lease::where('status', 'active')->orderBy('id')->first();
        $spareUnit = Unit::where('asset_id', $atriomWalk->id)
            ->where('status', 'vacant')
            ->orderBy('id')
            ->first();
        if ($multiLease && $spareUnit) {
            $multiLease->syncUnits([$multiLease->unit_id, $spareUnit->id], $multiLease->unit_id);
            $occupiedCount++;
            $vacantCount--;
        }

        $this->seedCurrentMonthPayments();
        $this->seedArAgingSpread();
        $this->seedPortalDemoInvoices();
        // After the portal tenant's invoices exist, give them a credit on account (needs open invoices).
        $this->seedTenantCredit();
        $this->seedVendors($atriomWalk);
        $this->seedTenantRequests();
        $this->seedTenantSalesDeclarations();
        $this->seedCamReconciliation($atriomWalk);
        $this->seedUtilityMeters($atriomWalk);
        $this->seedTenantNotes();
        $this->seedCreditNotes();
        $this->seedStaffAssignments($atriomWalk);

        // Auto-provision a budget for every property (current year), re-derive
        // them from billed levy items, then record a few demo spends.
        Artisan::call('marketing:ensure-budgets');
        Artisan::call('marketing:backfill-budgets');
        $this->seedMarketingSpends();
        $this->command->info('   Marketing budgets auto-provisioned + derived + demo spends');

        $this->seedMarketingPosts($atriomWalk);
        $this->command->info('   Shopper feed: store directory + live offers, one awaiting review');

        $this->seedAnnouncements($atriomWalk);
        $this->command->info('   Mall news: sent notices with read receipts, one scheduled, one draft');

        $this->seedViolations($atriomWalk);
        $this->seedCustomFields();
        $this->command->info('   House rules: a tariff on the register, four breaches, one fine billed');

        // --- Operational + financial modules (22–26 + AP / expenses / deposits) ---
        $employees = $this->seedHrEmployees($atriomWalk);
        $this->seedTreasuryCustody($employees);
        $this->seedInventory($atriomWalk);
        $this->seedFixedAssets($atriomWalk);
        $this->seedPreventiveMaintenance($atriomWalk);
        $this->seedVendorBills($atriomWalk);
        $this->seedExpenses($atriomWalk);
        $this->seedFacilityCosts($atriomWalk);
        $this->seedSecurityDeposits();
        $this->seedPostDatedCheques($atriomWalk);

        $plazaUnitCount = Unit::where('asset_id', $plazaAnnex->id)->count();
        $this->command->info("✅ Created {$this->primaryName()} with {$occupiedCount} occupied, {$vacantCount} vacant units (+ {$plazaUnitCount} vacant units on {$this->secondaryName()} demo asset)");
        $this->command->info('✅ Generated leases, charges, invoices, and payment history');
        $this->command->newLine();
        $this->command->info('📊 Demo metrics ('.$this->primaryName().'):');
        $this->command->info('   Occupancy: '.$atriomWalk->fresh()->occupancyRate().'%');
        $this->command->info('   Total leases: '.Lease::count());
        $this->command->info('   Total invoices: '.Invoice::count());
        $this->command->info('   Outstanding AR: EGP '.number_format(Invoice::whereIn('status', ['issued', 'partially_paid', 'overdue'])->sum('balance'), 2));

        // General Ledger (module 21): post the double-entry journal from EVERY
        // source document now that all of them exist. The sync sweep is windowed
        // (only posts documents in the recent window by default); `--all`
        // backfills the full history in one idempotent pass, so this must run
        // The leasing cycle: zones, rent ladders, options, events, a second CAM pool, an item
        // allocation with a disputed line, and a late-posted bill. Runs after the invoices and
        // vendors it builds on, and BEFORE the GL sync so everything it creates gets posted.
        $this->seedLeasingCycle($atriomWalk);

        // Units that were SOLD rather than let (module 37). Runs after the leasing cycle so it can
        // take units nobody leased, and BEFORE the GL sync so its assessments post like any invoice.
        $this->seedUnitOwnerships($atriomWalk);

        // LAST — after invoices, payments, credit notes, vendor bills, expenses,
        // deposits, payroll, advances, custody, stock movements, and fixed-asset
        // depreciation/disposals have all been seeded.
        $this->command->newLine();
        $this->command->info('📒 Posting General Ledger from all source documents (this may take a moment)...');
        Artisan::call('accounting:sync-ledger', ['--all' => true]);
        $this->command->info('   GL entries posted: '.JournalEntry::count().' journal entries');

        // Owner statements read the posted GL for the period, so they must run AFTER the sync.
        $this->seedOwnerStatements($atriomWalk);
    }

    /**
     * The leasing cycle, as an operating mall actually looks (the 43 Yardi-benchmark stories).
     *
     * **Why this exists.** Every capability shipped in the 2026-08 benchmark cycle was invisible in
     * the demo: `migrate:fresh --seed` produced a mall with no zones, no rent ladders, no options, no
     * lease events, one CAM pool, no disputes and no post-month overrides — so half the admin panel
     * opened onto an empty state and nothing exercised the new code paths outside the test suite.
     *
     * **Everything here goes through the real services.** Nothing is a raw insert of a shape no
     * workflow could produce: the ladder is written by `ChargeScheduleService`, the expansion by
     * `LeaseSpaceChangeService`, the dispute by `DisputeInvoiceItemService`. Demo data that could not
     * have been created by an operator is a lie that looks like a fixture, and it hides exactly the
     * bugs a seeder is supposed to surface.
     */
    private function seedLeasingCycle(Asset $asset): void
    {
        $this->command->info('🏗  Seeding the leasing cycle — zones, ladders, options, events, pools…');

        $zones = $this->seedZones($asset);
        $this->seedRentLadders();
        $this->seedRateBasedLease();
        $this->seedLeaseOptions();
        $this->seedLeaseEventsDemo();
        $this->seedFoodCourtPool($asset, $zones['A']);
        $this->seedPercentageRentTiers();
        $this->seedItemAllocationAndDispute();
        $this->seedLatePostedVendorBill($asset);
        $this->seedRentableItems($asset, $zones['A']);
        $this->seedRentIndices();
    }

    /**
     * The property's floor register — created once, then SELECTED by everything that stands on it.
     *
     * Floors replaced the free-text `units.floor` column: a mall has perhaps eight of them, and
     * retyping "Ground" on the ninetieth unit is how "G" and "Ground" become two floors.
     */
    private function floorFor(Asset $asset, string $label): Floor
    {
        [$code, $level] = match (strtolower($label)) {
            'basement', 'b1' => ['B1', -1],
            'ground', 'g' => ['G', 0],
            'mezzanine', 'm' => ['M', 1],
            default => [(string) (int) $label, (int) $label + 1],
        };

        return Floor::firstOrCreate(
            ['asset_id' => $asset->id, 'code' => $code],
            ['name' => $label, 'level' => $level],
        );
    }

    /**
     * Zones, so the mall has a geography (module 30) and a food-court CAM pool has participants.
     *
     * @return array<string, Area>
     */
    private function seedZones(Asset $asset): array
    {
        $zones = [];

        foreach ([
            'A' => ['name' => 'Zone A — Food court & premium retail', 'code' => 'ZA'],
            'B' => ['name' => 'Zone B — Retail & services', 'code' => 'ZB'],
            'C' => ['name' => 'Zone C — Upper level', 'code' => 'ZC'],
        ] as $key => $attrs) {
            $zones[$key] = Area::updateOrCreate(
                ['asset_id' => $asset->id, 'code' => $attrs['code']],
                ['name' => $attrs['name'], 'is_active' => true],
            );

            // The unit codes already carry the zone letter, which is what makes this honest rather
            // than arbitrary: the layout was always zoned, nothing recorded it.
            Unit::where('asset_id', $asset->id)
                ->where('code', 'like', $key.'-%')
                ->update(['area_id' => $zones[$key]->id]);
        }

        return $zones;
    }

    /**
     * The contracted rent ladder, written the day the lease was signed (LS-01).
     *
     * Before this the demo had one open-ended rent row per lease, so the rent roll showed no future
     * step, the expiration schedule had no income at risk to project, and straight-line rent had
     * nothing to average. The command is the same one an operator runs.
     */
    private function seedRentLadders(): void
    {
        Artisan::call('atriom:project-lease-schedules', ['--commit' => true]);

        $this->command->info('   Rent ladders projected onto leases with a contracted escalation.');
    }

    /** One anchor let at a rate per m² per year, the way commercial space is actually priced (LS-04). */
    private function seedRateBasedLease(): void
    {
        $lease = Lease::query()
            ->where('status', 'active')
            ->whereHas('unit', fn ($q) => $q->where('code', 'A-07'))   // the 110 m² F&B anchor
            ->first();

        if (! $lease) {
            return;
        }

        // The rate is DERIVED FROM THE RENT THIS LEASE ALREADY BILLS, not typed.
        //
        // It was a hardcoded 4,800/m²/yr, which on 110 m² derives 44,000 — while the lease had been
        // seeded at 77,000 and its charge schedule still said so. The model re-derived the COLUMN
        // and nothing touched the SCHEDULE, so the demo books carried a lease whose rent roll read
        // 44,000 and whose invoices billed 77,000, with no lease event to explain the difference —
        // and three years of escalation compounded from the wrong base. No operator can reach that
        // state (all three rent fields are locked on Edit, with "use the Change rent action"), so it
        // was the seeder contradicting itself, on the one lease written to prove the derivation runs.
        //
        // Computed, so it cannot drift again if the seeded rent changes.
        $area = (float) $lease->unit?->area_sqm;
        $rent = (float) $lease->base_rent_monthly;

        if ($area <= 0 || $rent <= 0) {
            return;
        }

        $lease->update([
            'rent_pricing_basis' => Lease::RENT_RATE,
            'base_rent_rate_per_sqm_year' => round($rent * 12 / $area, 2),
        ]);
    }

    /** Options and their notice windows (OP-01…OP-04) — including one closing soon, so the scan bites. */
    private function seedLeaseOptions(): void
    {
        $leases = Lease::query()->where('status', 'active')->with('unit')->take(4)->get();

        if ($leases->count() < 3) {
            return;
        }

        // A renewal whose notice window is OPEN and closes in three weeks: this is the row the
        // dashboard and `leases:scan-option-windows` exist for.
        LeaseOption::updateOrCreate(
            ['lease_id' => $leases[0]->id, 'type' => 'renewal'],
            [
                'status' => 'open',
                'earliest_notice_date' => Carbon::today()->subMonth()->toDateString(),
                'latest_notice_date' => Carbon::today()->addWeeks(3)->toDateString(),
                'term_months' => 36,
                // , the value  actually lists — 'uplift'
                // was a value no picker offers and no code path matches, so the demo mall's renewal
                // option carried an 8% uplift that could never have been applied.
                'rent_basis' => 'uplift_percent',
                'uplift_percent' => 8.00,
                'notes' => 'Five-year lease with one three-year renewal at 8% over the then-current rent.',
            ],
        );

        // An expansion right over the adjacent unit — the encumbrance the unit picker warns about.
        $neighbour = Unit::where('code', 'A-08')->first();

        if ($neighbour) {
            LeaseOption::updateOrCreate(
                ['lease_id' => $leases[1]->id, 'type' => 'expansion'],
                [
                    'status' => 'open',
                    'earliest_notice_date' => Carbon::today()->addMonths(2)->toDateString(),
                    'latest_notice_date' => Carbon::today()->addMonths(8)->toDateString(),
                    'unit_id' => $neighbour->id,
                    'rent_basis' => 'market',
                    'notes' => 'First right over A-08 if it comes free.',
                ],
            );
        }

        // A break clause the tenant did not take up — so the register shows a resolved row too.
        LeaseOption::updateOrCreate(
            ['lease_id' => $leases[2]->id, 'type' => 'termination'],
            [
                'status' => 'lapsed',
                'earliest_notice_date' => Carbon::today()->subMonths(8)->toDateString(),
                'latest_notice_date' => Carbon::today()->subMonths(5)->toDateString(),
                'penalty_amount' => 120000,
                'resolved_at' => Carbon::today()->subMonths(5)->toDateString(),
                'notes' => 'Break at month 24 against three months\' rent. Window passed unexercised.',
            ],
        );
    }

    /**
     * The commercial history a live lease accumulates (LE-01…LE-04).
     *
     * An expansion, a rent concession and a fit-out abatement — each recorded as a dated, reasoned
     * event through the service that owns it, so the lease's history reads like a real file.
     */
    private function seedLeaseEventsDemo(): void
    {
        $leases = Lease::query()->where('status', 'active')->with('unit', 'units')->take(8)->get();

        if ($leases->count() < 4) {
            return;
        }

        // ── An expansion into the adjacent unit, three months ago ──────────────────────────────
        $expanding = $leases[3];
        $spare = Unit::whereNotIn('status', ['occupied', 'reserved'])
            ->where('asset_id', $expanding->unit?->asset_id)
            ->first();

        if ($spare) {
            try {
                app(LeaseSpaceChangeService::class)->expand($expanding, [
                    'unit_ids' => [$spare->id],
                    'effective_from' => Carbon::today()->subMonths(3)->startOfMonth()->toDateString(),
                    'reason' => 'Tenant took the adjacent unit to extend the shopfront.',
                    'document_reference' => 'AMD-2026-014',
                ]);
            } catch (\Throwable $e) {
                $this->command->warn('   Skipped the demo expansion: '.$e->getMessage());
            }
        }

        // ── A six-month 25% rent concession, still running ─────────────────────────────────────
        try {
            app(LeaseReliefService::class)->grant($leases[4], [
                'type' => 'base_rent',
                'from' => Carbon::today()->subMonths(2)->startOfMonth()->toDateString(),
                'to' => Carbon::today()->addMonths(4)->endOfMonth()->toDateString(),
                'percent_off' => 25,
                'reason' => 'Trading concession while the north entrance is closed for works.',
                'document_reference' => 'CON-2026-003',
            ]);
        } catch (\Throwable $e) {
            $this->command->warn('   Skipped the demo relief: '.$e->getMessage());
        }
    }

    /**
     * A second recovery pool, scoped to the food court (RC-02) — with gross-up and a cap.
     *
     * Yardi's own example: everyone shares CAM, only the food court shares grease-trap cleaning.
     * Zone A is the F&B strip, so the participants are real rather than arranged.
     */
    private function seedFoodCourtPool(Asset $asset, Area $foodCourt): void
    {
        $year = (int) Carbon::today()->subYear()->year;

        $pool = CamExpensePool::updateOrCreate(
            ['asset_id' => $asset->id, 'period_year' => $year, 'pool_code' => 'fc_grease'],
            [
                'name' => 'Food court — grease trap & extraction',
                'participant_scope' => CamExpensePool::PARTICIPANTS_AREA,
                'participant_area_id' => $foodCourt->id,
                'total_actual_expense' => 384000,
                'total_estimated_collected' => 350000,
                'expense_basis' => CamExpensePool::BASIS_STATED,
                'estimate_basis' => CamExpensePool::BASIS_STATED,
                'denominator_basis' => CamExpensePool::DENOMINATOR_OCCUPIED,
                'gross_up_pct' => 95,
                'variable_pct' => 70,
                'admin_fee_pct' => 0.10,
                'admin_fee_on_net' => true,
                'recovery_vat_rate' => Vat::standardRate(),
                'status' => 'draft',
                'notes' => 'Extraction ducting, grease-trap pumping and the F&B waste contract. '
                    .'Only the food-court tenants participate; the main CAM pool covers the rest of the centre.',
            ],
        );

        try {
            app(CamReconciliationService::class)->generateAllocations($pool);
        } catch (\Throwable $e) {
            $this->command->warn('   Skipped the food-court allocations: '.$e->getMessage());
        }
    }

    /** Tiered percentage rent on an F&B lease — the shape a real turnover clause takes (PR-*). */
    private function seedPercentageRentTiers(): void
    {
        $lease = Lease::query()
            ->where('status', 'active')
            ->where('has_percentage_rent', true)
            ->first();

        if (! $lease) {
            return;
        }

        $lease->percentageRentTiers()->delete();

        // 6% over the first 4m of annual turnover, 8% over the next 4m, 10% beyond.
        foreach ([
            ['from_amount' => 0, 'to_amount' => 4000000, 'rate' => 6],
            ['from_amount' => 4000000, 'to_amount' => 8000000, 'rate' => 8],
            ['from_amount' => 8000000, 'to_amount' => null, 'rate' => 10],
        ] as $tier) {
            $lease->percentageRentTiers()->create($tier);
        }
    }

    /**
     * A tenant who paid the rent and is arguing about the service charge (MF-06 + MF-07).
     *
     * The pair is deliberate: without the item allocation the priority order would report the rent
     * as unpaid, and without the dispute the late-fee sweep would charge a penalty on a balance
     * nobody has agreed is owed. Together they are the story the two features exist for.
     */
    private function seedItemAllocationAndDispute(): void
    {
        // An invoice NOTHING has paid yet. On a part-paid one the priority order has already
        // settled the rent and eaten into the service charge, so there would be nothing left on
        // the CAM line to dispute — which is exactly what the first run of this seeder reported.
        $invoice = Invoice::query()
            ->whereIn('status', ['issued', 'overdue'])
            ->whereDoesntHave('payments')
            ->whereColumn('balance', 'total')
            ->whereHas('items', fn ($q) => $q->where('type', 'service_charge'))
            ->whereHas('items', fn ($q) => $q->where('type', 'base_rent'))
            ->with('items')
            ->first();

        if (! $invoice) {
            return;
        }

        /** @var InvoiceItem|null $rent */
        $rent = $invoice->items->firstWhere('type', 'base_rent');
        /** @var InvoiceItem|null $cam */
        $cam = $invoice->items->firstWhere('type', 'service_charge');

        if (! $rent || ! $cam || (float) $rent->total <= 0) {
            return;
        }

        // The tenant paid exactly the rent line and said so on the remittance advice.
        $payment = Payment::create([
            'tenant_id' => $invoice->tenant_id,
            'amount' => (float) $rent->total,
            'payment_date' => Carbon::today()->subDays(9)->toDateString(),
            'method' => 'bank_transfer',
            'bank_account_id' => $this->demoBankAccountFor('bank_transfer', 0),
            'status' => 'captured',
            'reference' => 'TRF-'.Carbon::today()->format('Ymd').'-DEMO',
            'notes' => 'Remittance advice: "March rent only — service charge under query."',
        ]);

        $payment->invoices()->attach($invoice->id, ['allocated_amount' => (float) $rent->total]);
        $invoice->refresh()->recomputeTotals();
        $invoice->save();

        try {
            app(AllocatePaymentToInvoiceItemsService::class)
                ->apply($payment->fresh(), $invoice->fresh(), [$rent->id => (float) $rent->total]);

            app(DisputeInvoiceItemService::class)->dispute(
                $cam->fresh(),
                'Tenant contests the service-charge reconciliation for the year; awaiting the audited pool.',
            );
        } catch (\Throwable $e) {
            $this->command->warn('   Skipped the demo dispute: '.$e->getMessage());
        }
    }

    /**
     * The car park, the storage cages and the signage — let alongside the leases (space model).
     *
     * Without these the whole rentable-item side of the app opens onto an empty state, and nothing
     * exercises the code path that keeps parking OUT of gross leasable area. Everything is assigned
     * through `AssignRentableItemService`, so the leases end up with a real `parking` charge that
     * the monthly run bills like any other.
     */
    /**
     * A short run of published index figures, so the CPI clause is visible in demo.
     *
     * **Demo data only, and deliberately not part of `atriom:install`.** A real install starts with
     * an EMPTY register: the whole point of the register is that the system records what the
     * statistical agency published and never invents a figure, so shipping invented numbers as
     * reference data would contradict the feature in its own seed. Here they are plainly demo,
     * alongside a demo mall and demo tenants.
     *
     * Roughly Egypt's recent shape — high, decelerating — because a flat 2% series would never
     * exercise the ceiling, and the ceiling is the term that matters in this market.
     */
    private function seedRentIndices(): void
    {
        $series = [
            '2025-09-01' => 100.0,
            '2025-12-01' => 104.8,
            '2026-03-01' => 110.2,
            '2026-06-01' => 115.1,
            '2026-09-01' => 119.6,
        ];

        foreach ($series as $period => $value) {
            RentIndex::updateOrCreate(
                ['code' => 'EGY_CPI', 'period' => $period],
                [
                    'value' => $value,
                    'published_on' => CarbonImmutable::parse($period)->addMonth()->day(10)->toDateString(),
                    'notes' => 'Demo series — urban CPI, rebased to 100.',
                ],
            );
        }
    }

    private function seedRentableItems(Asset $asset, Area $foodCourt): void
    {
        $basement = $this->floorFor($asset, 'Basement');

        // 40 bays in the basement, 6 storage cages, 2 signage faces on the food-court frontage.
        $items = [];

        foreach (range(1, 40) as $n) {
            $items[] = RentableItem::updateOrCreate(
                ['asset_id' => $asset->id, 'code' => 'P-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT)],
                [
                    'type' => RentableItem::TYPE_PARKING,
                    'floor_id' => $basement->id,
                    'name' => $n <= 8 ? 'Covered bay, north ramp' : null,
                    'monthly_rate' => $n <= 8 ? 1200 : 900,
                ],
            );
        }

        foreach (range(1, 6) as $n) {
            $items[] = RentableItem::updateOrCreate(
                ['asset_id' => $asset->id, 'code' => 'ST-'.str_pad((string) $n, 2, '0', STR_PAD_LEFT)],
                [
                    'type' => RentableItem::TYPE_STORAGE,
                    'floor_id' => $basement->id,
                    'name' => 'Storage cage',
                    'monthly_rate' => 1800,
                ],
            );
        }

        foreach (range(1, 2) as $n) {
            RentableItem::updateOrCreate(
                ['asset_id' => $asset->id, 'code' => 'SIGN-'.$n],
                [
                    'type' => RentableItem::TYPE_SIGNAGE,
                    'area_id' => $foodCourt->id,
                    'name' => 'Food-court totem face',
                    'monthly_rate' => 6500,
                ],
            );
        }

        // One bay out of service, so the register shows the state and the assign picker excludes it.
        RentableItem::where('asset_id', $asset->id)->where('code', 'P-040')
            ->update(['status' => RentableItem::STATUS_OUT_OF_SERVICE, 'notes' => 'Bollard damaged; awaiting repair.']);

        // Let a handful to real tenants, from the start of the year.
        $service = app(AssignRentableItemService::class);
        $leases = Lease::query()->where('status', 'active')->with('unit')->take(5)->get();
        $pool = collect($items)->filter(fn (RentableItem $i) => $i->code !== 'P-040')->values();
        $next = 0;

        foreach ($leases as $index => $lease) {
            // The anchor takes four bays and a cage; the rest take one or two.
            $take = $index === 0 ? 5 : ($index < 3 ? 2 : 1);

            foreach (range(1, $take) as $ignored) {
                $item = $pool[$next++] ?? null;

                if (! $item) {
                    break 2;
                }

                try {
                    $service->assign($lease->fresh(), $item->fresh(), [
                        'effective_from' => Carbon::today()->startOfYear()->toDateString(),
                    ]);
                } catch (\Throwable $e) {
                    $this->command->warn('   Skipped letting '.$item->code.': '.$e->getMessage());
                }
            }
        }
    }

    /**
     * A vendor bill that arrived after its own month closed, posted to the current one (MF-05).
     *
     * The document keeps its real date — that is what the vendor invoiced — and only the journal
     * entry moves. Without a row like this the post-month override is a feature with nothing to look
     * at, and nobody would discover it existed.
     */
    private function seedLatePostedVendorBill(Asset $asset): void
    {
        // Vendors are SHARED, not property-owned — they carry no `asset_id` (see
        // `App\Support\PropertyIsolation`). The BILL is what belongs to the property.
        $vendor = Vendor::query()->first();

        if (! $vendor) {
            return;
        }

        $documentDate = Carbon::today()->subMonths(2)->startOfMonth()->addDays(21);

        $bill = VendorBill::updateOrCreate(
            ['number' => 'SUP-LATE-'.$documentDate->format('Ym')],
            [
                'vendor_id' => $vendor->id,
                'asset_id' => $asset->id,
                'category' => 'maintenance',
                'bill_date' => $documentDate->toDateString(),
                'due_date' => $documentDate->copy()->addDays(30)->toDateString(),
                'subtotal' => 48000,
                'vat_amount' => 0,
                'total' => 48000,
                'status' => 'approved',
                'description' => 'Chiller service. Invoice reached accounts payable six weeks late.',
            ],
        );

        try {
            app(SetPostMonthService::class)->set(
                $bill->fresh(),
                Carbon::today()->startOfMonth()->toDateString(),
                'Invoice received after the period it belongs to had closed.',
            );
        } catch (\Throwable $e) {
            $this->command->warn('   Skipped the post-month demo: '.$e->getMessage());
        }
    }

    /**
     * Guarantee each portal-login tenant (tenant1/2/3) has one fresh UNPAID
     * invoice, so the tenant-portal "Pay Now" demo always has something to pay
     * (the button only shows when balance > 0). Runs after the payment seeders
     * so nothing settles these.
     */
    private function seedPortalDemoInvoices(): void
    {
        $tenants = Tenant::whereIn('email', [
            'tenant1@'.$this->emailDomain(),
            'tenant2@'.$this->emailDomain(),
            'tenant3@'.$this->emailDomain(),
        ])->with('leases.unit')->get();

        $issueDate = Carbon::now()->startOfMonth();
        $created = 0;

        foreach ($tenants as $tenant) {
            $lease = $tenant->leases->first();
            if (! $lease) {
                continue;
            }

            $rent = (float) $lease->base_rent_monthly;
            $service = (float) $lease->service_charge_monthly;
            $marketing = round($rent * 0.05, 2); // 5% marketing levy, charged to the tenant
            $vat = round($service * 0.14, 2);
            $subtotal = $rent + $service + $marketing;
            $total = round($subtotal + $vat, 2);

            $invoice = Invoice::create([
                'lease_id' => $lease->id,
                'tenant_id' => $tenant->id,
                'status' => 'issued',
                'issue_date' => $issueDate,
                'due_date' => Carbon::now()->addDays(7),
                'period_start' => $issueDate,
                'period_end' => $issueDate->copy()->endOfMonth(),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => $total,
                'paid_amount' => 0,
                'balance' => $total,
                'currency' => 'EGP',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Monthly Rent - '.$issueDate->format('F Y'),
                'type' => 'base_rent',
                'amount' => $rent,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'total' => $rent,
            ]);
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Service Charge - '.$issueDate->format('F Y'),
                'type' => 'service_charge',
                'amount' => $service,
                'vat_rate' => Vat::standardRate(),
                'vat_amount' => $vat,
                'total' => $service + $vat,
            ]);
            // Marketing levy line — funds the property marketing budget (derived).
            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Marketing Levy - '.$issueDate->format('F Y'),
                'type' => 'marketing',
                'amount' => $marketing,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'total' => $marketing,
            ]);

            $created++;
        }

        $this->command->info("   Seeded {$created} unpaid portal-demo invoices (Pay Now targets)");
    }

    /**
     * Realistic mall maintenance: one urgent, one in-progress, one awaiting tenant,
     * one resolved, one closed. Spreads across the three portal tenants so any
     * /portal login has something to see.
     */
    private function seedTenantRequests(): void
    {
        $tenants = Tenant::whereIn('email', [
            'tenant1@'.$this->emailDomain(),
            'tenant2@'.$this->emailDomain(),
            'tenant3@'.$this->emailDomain(),
        ])->with(['leases.unit'])->get()->keyBy('email');

        $manager = User::where('email', 'manager@mall.test')->first();
        $admin = User::where('email', 'admin@mall.test')->first();

        if ($tenants->isEmpty() || ! $manager) {
            return;
        }

        $seedData = [
            // tenant1 — Cilantro (A-01) — urgent + open
            [
                'tenant_email' => 'tenant1@atriomwalk.test',
                'title' => 'AC unit blowing warm air',
                'description' => 'Customer area AC has been blowing warm air since yesterday morning. With the heat, we are losing sit-down customers. Need urgent fix.',
                'category' => 'hvac',
                'channel' => 'whatsapp',
                'priority' => 'urgent',
                'submitted_days_ago' => 1,
                'status' => 'in_progress',
                'assign_to' => $manager,
                'comments' => [
                    ['by' => 'manager', 'body' => 'Acknowledged. HVAC contractor (Cool-Air) dispatched for today afternoon.', 'internal' => false],
                    ['by' => 'manager', 'body' => 'Vendor PO #4421 raised, EGP 1,800 estimated.', 'internal' => true],
                ],
            ],

            // tenant1 — older, resolved one
            [
                'tenant_email' => 'tenant1@atriomwalk.test',
                'title' => 'Front signage light flickering',
                'description' => 'The Cilantro sign at the entrance flickers at night.',
                'category' => 'electrical',
                'channel' => 'portal',
                'priority' => 'medium',
                'submitted_days_ago' => 22,
                'resolved_days_ago' => 19,
                'status' => 'resolved',
                'assign_to' => $manager,
                'resolution_notes' => 'Replaced faulty driver and two LED modules. Verified at night.',
                'csat' => 5,
                'csat_comment' => 'Quick turnaround, looks great at night now. Thank you!',
            ],

            // tenant2 — Magrabi Optical (A-02) — awaiting tenant
            [
                'tenant_email' => 'tenant2@atriomwalk.test',
                'title' => 'Leak from ceiling near display cases',
                'description' => 'There is water dripping from one of the ceiling tiles near our front display. We placed a bucket but need this checked before stock is damaged.',
                'category' => 'plumbing',
                'channel' => 'phone',
                'priority' => 'high',
                'submitted_days_ago' => 4,
                'status' => 'awaiting_tenant',
                'assign_to' => $manager,
                'comments' => [
                    ['by' => 'manager', 'body' => 'Plumber visited and traced to AC condensation line on the floor above. Can we come back on Tuesday morning to seal the line? Need access to your unit.', 'internal' => false],
                ],
            ],

            // tenant2 — closed
            [
                'tenant_email' => 'tenant2@atriomwalk.test',
                'title' => 'Door auto-closer too tight',
                'description' => 'Glass door is hard to push for elderly customers.',
                'category' => 'structural',
                'channel' => 'email',
                'priority' => 'low',
                'submitted_days_ago' => 60,
                'resolved_days_ago' => 55,
                'closed_days_ago' => 48,
                'status' => 'closed',
                'assign_to' => $manager,
                'resolution_notes' => 'Loosened spring tension. Customer confirmed door now opens easily.',
            ],

            // tenant3 — Buffalo Burger — acknowledged, just opened
            [
                'tenant_email' => 'tenant3@atriomwalk.test',
                'title' => 'Fire alarm beeping every 2 minutes',
                'description' => 'The small fire-alarm sensor near the kitchen has been beeping every couple of minutes since this morning. Probably low battery. We did not touch it.',
                'category' => 'safety',
                'channel' => 'walk_in',
                'priority' => 'high',
                'submitted_days_ago' => 0,
                'status' => 'acknowledged',
                'assign_to' => $manager,
            ],

            // --- Non-maintenance request types (the generalised Tenant Request system) ---

            // tenant1 — Complaint about a neighbour — resolved, rated
            [
                'tenant_email' => 'tenant1@atriomwalk.test',
                'request_type' => 'complaint',
                'title' => 'Loud music from neighbouring unit after hours',
                'description' => 'The unit next door plays loud music well past closing, disturbing our evening diners. Could the team have a word?',
                'category' => 'noise',
                'channel' => 'portal',
                'priority' => 'medium',
                'submitted_days_ago' => 12,
                'resolved_days_ago' => 9,
                'status' => 'resolved',
                'assign_to' => $manager,
                'resolution_notes' => 'Spoke with the neighbouring tenant; they agreed to lower volume after 9pm. Will monitor.',
                'csat' => 4,
                'csat_comment' => 'Handled politely. Hope it sticks.',
            ],

            // tenant2 — General inquiry — open, no SLA, no sub-category
            [
                'tenant_email' => 'tenant2@atriomwalk.test',
                'request_type' => 'inquiry',
                'title' => 'Eid holiday trading hours?',
                'description' => 'Can you confirm the mall opening hours during the Eid holiday so we can plan staffing?',
                'channel' => 'portal',
                'priority' => 'low',
                'submitted_days_ago' => 2,
                'status' => 'submitted',
            ],

            // tenant3 — Access request — parking permit — acknowledged
            [
                'tenant_email' => 'tenant3@atriomwalk.test',
                'request_type' => 'access',
                'title' => 'Extra parking permit for new manager',
                'description' => 'We hired a new branch manager and need an additional basement parking permit.',
                'category' => 'parking',
                'channel' => 'email',
                'priority' => 'low',
                'submitted_days_ago' => 5,
                'status' => 'acknowledged',
                'assign_to' => $manager,
            ],

            // tenant1 — Billing query — in progress, routed to accounting, no SLA
            [
                'tenant_email' => 'tenant1@atriomwalk.test',
                'request_type' => 'billing',
                'title' => 'Service charge on latest invoice looks high',
                'description' => 'The service charge on this month\'s invoice is noticeably higher than last month. Could you break it down for us?',
                'channel' => 'portal',
                'priority' => 'medium',
                'submitted_days_ago' => 3,
                'status' => 'in_progress',
                'assign_to' => $manager,
            ],

            // tenant2 — Document request — lease copy — closed, rated
            [
                'tenant_email' => 'tenant2@atriomwalk.test',
                'request_type' => 'document',
                'title' => 'Copy of signed lease agreement',
                'description' => 'Our accountant needs a PDF copy of our current signed lease for the annual audit.',
                'category' => 'lease_copy',
                'channel' => 'portal',
                'priority' => 'low',
                'submitted_days_ago' => 30,
                'resolved_days_ago' => 28,
                'closed_days_ago' => 25,
                'status' => 'closed',
                'assign_to' => $manager,
                'resolution_notes' => 'Emailed the signed lease PDF and uploaded a copy to the tenant\'s document folder.',
                'csat' => 5,
            ],
        ];

        $created = 0;

        foreach ($seedData as $row) {
            $tenant = $tenants->get($row['tenant_email']);
            if (! $tenant) {
                continue;
            }

            $lease = $tenant->leases->first();
            $unit = $lease?->unit;
            if (! $unit) {
                continue;
            }

            $submittedAt = Carbon::now()->subDays($row['submitted_days_ago'])->subHours(rand(1, 6));
            $slaHours = config("sla.{$row['priority']}.resolve_hours", 168);
            $type = TenantRequestType::tryFrom($row['request_type'] ?? 'maintenance') ?? TenantRequestType::default();

            $request = TenantRequest::create([
                'reference' => TenantRequest::generateReference($unit->asset->code, $type->referencePrefix()),
                'tenant_id' => $tenant->id,
                'unit_id' => $unit->id,
                'lease_id' => $lease->id,
                'request_type' => $type->value,
                'status' => 'submitted',
                'priority' => $row['priority'],
                'category' => $row['category'] ?? null,
                'channel' => $row['channel'] ?? 'portal',
                'title' => $row['title'],
                'description' => $row['description'],
                'submitted_at' => $submittedAt,
                // Only SLA-bearing types carry a resolution deadline.
                'target_resolution_at' => $type->hasSla() ? $submittedAt->copy()->addHours($slaHours) : null,
            ]);

            // Walk the request through the requested status using legal transitions.
            if (in_array($row['status'], ['acknowledged', 'in_progress', 'awaiting_tenant', 'resolved', 'closed'], true)) {
                $request->update([
                    'status' => 'acknowledged',
                    'acknowledged_at' => $submittedAt->copy()->addMinutes(rand(15, 240)),
                    'assigned_to' => $row['assign_to']->id ?? null,
                ]);
            }

            if (in_array($row['status'], ['in_progress', 'awaiting_tenant', 'resolved', 'closed'], true)) {
                $request->update(['status' => 'in_progress']);
            }

            if ($row['status'] === 'awaiting_tenant') {
                $request->update(['status' => 'awaiting_tenant']);
            }

            if (in_array($row['status'], ['resolved', 'closed'], true)) {
                $resolvedAt = Carbon::now()->subDays($row['resolved_days_ago']);
                $request->update([
                    'status' => 'resolved',
                    'resolved_at' => $resolvedAt,
                    'resolution_notes' => $row['resolution_notes'] ?? 'Resolved.',
                ]);
            }

            if ($row['status'] === 'closed') {
                $request->update([
                    'status' => 'closed',
                    'closed_at' => Carbon::now()->subDays($row['closed_days_ago']),
                ]);
            }

            // Close-out satisfaction on resolved/closed demo rows.
            if (isset($row['csat']) && in_array($row['status'], ['resolved', 'closed'], true)) {
                $request->update([
                    'csat_rating' => $row['csat'],
                    'csat_comment' => $row['csat_comment'] ?? null,
                ]);
            }

            foreach ($row['comments'] ?? [] as $c) {
                $author = $c['by'] === 'manager' ? $manager : ($c['by'] === 'admin' ? $admin : $tenant);
                TenantRequestComment::create([
                    'tenant_request_id' => $request->id,
                    'author_type' => $author->getMorphClass(),
                    'author_id' => $author->getKey(),
                    'body' => $c['body'],
                    'is_internal' => $c['internal'] ?? false,
                    'created_at' => $submittedAt->copy()->addHours(rand(2, 12)),
                    'updated_at' => $submittedAt->copy()->addHours(rand(2, 12)),
                ]);
            }

            $created++;
        }

        $this->command->info("   Seeded {$created} tenant requests (maintenance + complaint/inquiry/access/billing/document)");
    }

    /**
     * Seed three months of historic sales declarations for active leases with
     * percentage-rent terms, mixing statuses (locked, submitted, disputed) so the
     * triage queue + locked-and-billed flow both have demo data on first login.
     */
    private function seedTenantSalesDeclarations(): void
    {
        $percentageLeases = Lease::where('status', 'active')
            ->where('has_percentage_rent', true)
            ->with('tenant')
            ->get();

        if ($percentageLeases->isEmpty()) {
            return;
        }

        $service = app(PercentageRentCalculationService::class);
        $superAdmin = User::where('email', 'admin@mall.test')->first();
        $created = 0;
        $locked = 0;

        foreach ($percentageLeases as $i => $lease) {
            // 3 historic months ending last month
            for ($monthsBack = 3; $monthsBack >= 1; $monthsBack--) {
                $periodStart = Carbon::now()->startOfMonth()->subMonths($monthsBack);
                $periodEnd = $periodStart->copy()->endOfMonth();

                // Simulate realistic sales: roughly 8-15x the rent (so percentage rent fires ~half the time)
                $multiplier = 6 + (($i * 7 + $monthsBack * 3) % 10);
                $sales = (float) $lease->base_rent_monthly * $multiplier;
                $sales = round($sales / 100, 0) * 100; // round to nearest 100 EGP

                // Vary status: month 3 (oldest) locked, month 2 mixed, month 1 (most recent) submitted
                $status = match (true) {
                    $monthsBack === 3 => 'locked',
                    $monthsBack === 2 && $i % 5 === 0 => 'disputed',
                    $monthsBack === 2 => 'locked',
                    default => 'submitted',
                };

                // File-first: a still-submitted declaration has no figure yet —
                // the tenant uploaded their report and staff enter the number on
                // review. Historic locked/disputed rows keep their reconciled
                // figure (they were already processed).
                $isPending = $status === 'submitted';

                $declaration = TenantSalesDeclaration::create([
                    'lease_id' => $lease->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'declared_sales' => $isPending ? null : $sales,
                    'declared_at' => $periodEnd->copy()->addDays(3),
                    'declared_by_type' => MorphMap::alias(Tenant::class),
                    'declared_by_id' => $lease->tenant_id,
                    'status' => 'submitted', // start as submitted; lock below if status === 'locked'
                ]);

                // Every declaration carries the tenant's uploaded sales report.
                $this->attachDemoSalesReport($declaration, $lease, $periodStart);

                if (! $isPending) {
                    $service->recalculate($declaration);
                }
                $created++;

                if ($status === 'locked' && $superAdmin) {
                    $service->lock($declaration->refresh(), $superAdmin, 'Reviewed and reconciled.');
                    $locked++;
                } elseif ($status === 'disputed') {
                    $declaration->update([
                        'status' => 'disputed',
                        'audit_notes' => 'POS audit shows sales above declared figure — clarification requested.',
                    ]);
                }
            }
        }

        $this->command->info("   Seeded {$created} tenant sales declarations ({$locked} locked → percentage rent charges generated)");
    }

    /**
     * Attach a small, openable PDF "sales report" to a demo declaration so the
     * file-first flow is visible in the admin panel (paper-clip in the table,
     * a downloadable file on the record). Built from a string — no fixture on
     * disk — with a correctly-computed stream length so any viewer renders it.
     */
    private function attachDemoSalesReport(TenantSalesDeclaration $declaration, Lease $lease, Carbon $periodStart): void
    {
        $title = 'Sales Report - '.$periodStart->isoFormat('MMMM YYYY').' - '.$lease->reference;
        $stream = "BT /F1 13 Tf 40 90 Td ({$title}) Tj ET";
        $len = strlen($stream);

        $pdf = "%PDF-1.4\n"
            ."1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n"
            ."2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj\n"
            ."3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 520 150]/Resources<</Font<</F1 5 0 R>>>>/Contents 4 0 R>>endobj\n"
            ."4 0 obj<</Length {$len}>>stream\n{$stream}\nendstream endobj\n"
            ."5 0 obj<</Type/Font/Subtype/Type1/BaseFont/Helvetica>>endobj\n"
            ."trailer<</Root 1 0 R>>\n%%EOF";

        $declaration->addMediaFromString($pdf)
            ->usingFileName('sales-report-'.$periodStart->format('Y-m').'.pdf')
            ->toMediaCollection(TenantSalesDeclaration::REPORT_COLLECTION);
    }

    /**
     * Seed two CAM expense pools for the asset:
     *  - Last year (reconciled, all allocations already billed) — demonstrates a completed annual cycle
     *  - This year (draft, no allocations yet) — gives the admin a live "Generate Allocations" click target
     */
    private function seedCamReconciliation(Asset $asset): void
    {
        $service = app(CamReconciliationService::class);
        $superAdmin = User::where('email', 'admin@mall.test')->first();

        // Last year — reconciled + all billed
        $lastYear = now()->subYear()->year;
        $lastYearActual = 825000;       // EGP 825k actual annual CAM
        $lastYearEstimated = 760000;    // EGP 760k collected from monthly estimates → 65k under-collected
        $closedPool = CamExpensePool::create([
            'asset_id' => $asset->id,
            'period_year' => $lastYear,
            'total_actual_expense' => $lastYearActual,
            'total_estimated_collected' => $lastYearEstimated,
            'admin_fee_pct' => 0.10,   // 10% management fee on the recovered share (billed +14% VAT at reconciliation)
            'status' => 'reconciling',
            'notes' => 'Includes security, cleaning, common-area HVAC, lobby lighting, landscaping. 10% admin fee.',
        ]);

        // One anchor tenant negotiated a CAM cap — their cost share can't rise more than 5%/yr
        // from a base year. Demonstrates the cap clause (caps the true-up + fee only; the raw
        // allocation stays uncapped, so the books still tie out).
        $anchorLease = Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $asset->id))
            ->where('status', 'active')
            ->first();
        if ($anchorLease) {
            LeaseCamTerm::create([
                'lease_id' => $anchorLease->id,
                'effective_year' => $lastYear - 1,
                'cap_type' => 'yoy',
                'base_year' => $lastYear - 1,
                'base_year_amount' => 18000,
                'yoy_pct' => 0.05,
                'compounding' => true,
                'notes' => 'Anchor tenant — CAM increase capped at 5% YoY.',
            ]);
        }

        $service->generateAllocations($closedPool);

        // Bill every allocation so it lands as a Charge on each lease
        foreach ($closedPool->allocations as $allocation) {
            $service->bill($allocation);
        }

        $closedPool->update([
            'status' => 'reconciled',
            'reconciled_at' => now()->subMonths(2),
            'reconciled_by_user_id' => $superAdmin?->id,
        ]);

        // Current year — draft, ready for demo
        CamExpensePool::create([
            'asset_id' => $asset->id,
            'period_year' => now()->year,
            'total_actual_expense' => 612000,   // YTD partial — security + cleaning + HVAC + landscaping accrued so far
            'total_estimated_collected' => 580000,
            'admin_fee_pct' => 0.10,
            'status' => 'draft',
            'notes' => 'YTD accrued expenses. Annual reconciliation runs at year end.',
        ]);

        $this->command->info("   Seeded 2 CAM pools ({$lastYear} reconciled + {$closedPool->allocations()->count()} allocations billed, ".now()->year.' draft awaiting generation)');
    }

    /**
     * Seed utility meters + 12 months of monthly readings so the
     * Energy Consumption chart on the dashboard has real-looking data and
     * the Energy resource shows a populated meter list.
     *
     * Mix:
     *  - 3 common-area meters per type (electric/water/gas) for the asset
     *  - 1 electric + 1 water meter per occupied unit (gas only on F&B units)
     */
    private function seedTenantNotes(): void
    {
        $admin = User::where('email', 'admin@mall.test')->first();
        $manager = User::where('email', 'manager@mall.test')->first();

        if (! $admin || ! $manager) {
            return;
        }

        // Pick the 3 portal-login tenants + a couple of others for variety
        $tenants = Tenant::query()
            ->whereIn('email', ['tenant1@atriomwalk.test', 'tenant2@atriomwalk.test', 'tenant3@atriomwalk.test'])
            ->orWhereNotNull('email')
            ->limit(8)
            ->get();

        $templates = [
            ['channel' => 'whatsapp', 'subject' => 'Invoice reminder', 'body' => 'Sent reminder for outstanding invoice. Tenant confirmed bank transfer would be done by EOD.', 'days' => 2],
            ['channel' => 'call', 'subject' => 'Sales declaration follow-up', 'body' => 'Called to nudge on last month\'s sales declaration submission. Tenant promised to submit by Friday.', 'days' => 5],
            ['channel' => 'meeting', 'subject' => 'Lease renewal discussion', 'body' => 'In-person meeting to discuss renewal terms. Tenant requested 5% lower escalation rate; will revert with counter-proposal.', 'days' => 12],
            ['channel' => 'site_visit', 'subject' => 'AC complaint follow-up', 'body' => 'On-site inspection after maintenance ticket. Issue resolved, tenant satisfied.', 'days' => 18],
            ['channel' => 'email', 'subject' => 'CAM reconciliation explanation', 'body' => 'Sent breakdown of CAM allocation methodology. Tenant accountant requested supporting invoices, sent separately.', 'days' => 25],
            ['channel' => 'call', 'subject' => 'Late payment notice', 'body' => 'Cordial reminder call. Tenant flagged cash-flow issue but committed to payment plan starting next week.', 'days' => 40],
            ['channel' => 'whatsapp', 'subject' => 'New unit availability', 'body' => 'Tenant asked about availability of a larger unit. Sent A-12 specs (120 sqm).', 'days' => 55],
        ];

        $created = 0;
        foreach ($tenants as $i => $tenant) {
            // 1-3 notes per tenant
            $count = ($i % 3) + 1;
            for ($n = 0; $n < $count; $n++) {
                $tpl = $templates[($i * 7 + $n) % count($templates)];
                Note::create([
                    'noteable_type' => MorphMap::alias(Tenant::class),
                    'noteable_id' => $tenant->id,
                    'author_id' => ($i + $n) % 2 === 0 ? $admin->id : $manager->id,
                    'channel' => $tpl['channel'],
                    'subject' => $tpl['subject'],
                    'body' => $tpl['body'],
                    'contacted_at' => Carbon::now()->subDays($tpl['days'])->setTime(10 + ($n * 2), 15 + ($n * 5)),
                ]);
                $created++;
            }
        }

        $this->command->info("   Seeded {$created} tenant communication notes");
    }

    private function seedUtilityMeters(Asset $asset): void
    {
        $meterSeq = 1;
        $created = 0;
        $readings = 0;

        $createMeter = function (?Unit $unit, string $type, string $provider) use ($asset, &$meterSeq, &$created, &$readings): UtilityMeter {
            $uom = match ($type) {
                'electric' => 'kWh',
                'water' => 'm³',
                'gas' => 'm³',
                default => 'unit',
            };

            $meter = UtilityMeter::create([
                'asset_id' => $asset->id,
                'unit_id' => $unit?->id,
                'meter_number' => sprintf('M-%s-%04d', strtoupper(substr($type, 0, 1)), $meterSeq++),
                'type' => $type,
                'provider' => $provider,
                'status' => 'active',
                'unit_of_measurement' => $uom,
            ]);
            $created++;

            // 12 months of monthly readings, ending current month.
            // Consumption magnitudes calibrated to look realistic for a mall context.
            $baseMonthly = match ($type) {
                'electric' => $unit ? (200 + ($meter->id * 13) % 800) : 12000,   // unit ~200-1000 kWh, common ~12,000 kWh
                'water' => $unit ? (5 + ($meter->id * 3) % 25) : 350,            // unit ~5-30 m³, common ~350 m³
                'gas' => $unit ? (10 + ($meter->id * 5) % 40) : 200,             // unit ~10-50 m³, common ~200 m³
                default => 100,
            };
            $unitCost = match ($type) {
                'electric' => 2.20, // EGP/kWh
                'water' => 6.50,    // EGP/m³
                'gas' => 4.80,      // EGP/m³
                default => 1.0,
            };

            $cumulative = 0;
            for ($monthsBack = 11; $monthsBack >= 0; $monthsBack--) {
                // ±15% jitter so the chart looks lived-in
                $jitter = 1 + (((($meter->id + $monthsBack) * 17) % 30) - 15) / 100;
                $consumption = round($baseMonthly * $jitter, 2);
                $cumulative += $consumption;

                MeterReading::create([
                    'utility_meter_id' => $meter->id,
                    'reading_date' => Carbon::now()->startOfMonth()->subMonths($monthsBack)->endOfMonth()->startOfDay(),
                    'reading_value' => $cumulative,
                    'consumption' => $consumption,
                    'cost' => round($consumption * $unitCost, 2),
                ]);
                $readings++;
            }

            return $meter;
        };

        // Common-area meters
        $createMeter(null, 'electric', 'North Cairo Electricity');
        $createMeter(null, 'water', 'Cairo Water Co.');
        $createMeter(null, 'gas', 'EgyptGas');

        // Per-unit meters on occupied units only — keeps the dashboard usable
        $occupiedUnits = Unit::where('asset_id', $asset->id)
            ->where('status', 'occupied')
            ->limit(20) // cap to first 20 occupied to keep seed time reasonable
            ->get();

        foreach ($occupiedUnits as $unit) {
            $createMeter($unit, 'electric', 'North Cairo Electricity');
            $createMeter($unit, 'water', 'Cairo Water Co.');
            // Gas only for F&B units (common in mall food courts)
            if ($unit->category === 'food_beverage') {
                $createMeter($unit, 'gas', 'EgyptGas');
            }
        }

        $this->command->info("   Seeded {$created} utility meters with {$readings} monthly readings");
    }

    /**
     * Seed a handful of payments dated in the current month so the
     * "Collected This Month" KPI and MoM delta look healthy in the demo.
     */
    private function seedCurrentMonthPayments(int $count = 7): void
    {
        $now = Carbon::now();

        // orderByRaw('id * 17 % 101') is a deterministic pseudo-random
        // shuffle — gives a "looks random" spread without depending on
        // SQLite's RANDOM() (which can't be seeded). Same pattern used
        // for AR-aging spread + credit notes below.
        $invoices = Invoice::where('balance', '>', 0)
            ->orderByRaw('(id * 17) % 101')
            ->limit($count * 3)
            ->get();

        $created = 0;
        foreach ($invoices as $invoice) {
            if ($created >= $count) {
                break;
            }

            $amount = min((float) $invoice->balance, (float) $invoice->total * (rand(40, 100) / 100));
            if ($amount < 100) {
                continue;
            }

            $payDate = $now->copy()->subDays(rand(0, 12));

            $payment = Payment::create([
                'reference' => Payment::generateReference(),
                'tenant_id' => $invoice->tenant_id,
                'amount' => round($amount, 2),
                'currency' => 'EGP',
                'method' => $method = collect(['bank_transfer', 'instapay', 'card', 'cheque'])->random(),
                'bank_account_id' => $this->demoBankAccountFor($method, $invoice->id),
                'status' => 'captured',
                'payment_date' => $payDate,
            ]);

            $invoice->payments()->attach($payment->id, ['allocated_amount' => round($amount, 2)]);

            // Derive paid/balance/status from the allocation rather than writing them — the
            // project invariant (Invoice::recomputeTotals is the single source of truth for AR).
            // Hand-writing them produced demo data that was only correct until something
            // recomputed, at which point the seeded figures were silently replaced.
            $invoice->recomputeTotals();

            $created++;
        }

        $this->command->info("   Seeded {$created} current-month payments");
    }

    /**
     * Give the portal demo tenant (tenant1) a CREDIT ON ACCOUNT so the new credit-balance feature is
     * visible + tryable: they overpaid — one invoice is settled in full and a surplus is held on
     * account (booked to Unearned Revenue). The surplus surfaces as Tenant::creditBalance() (the
     * portal "Credit on account" stat) and the operator can draw it down on another open invoice via
     * the "Apply tenant credit" action. Uses the real recomputeTotals path, not a manual AR poke.
     */
    private function seedTenantCredit(): void
    {
        $tenant = Tenant::where('email', 'tenant1@atriomwalk.test')->first();
        if (! $tenant) {
            return;
        }

        $open = $tenant->invoices()->where('balance', '>', 0)->orderBy('due_date')->get();
        if ($open->count() < 2) {
            return; // need one to fully settle + at least one left open as the "Apply credit" target
        }

        $payOff = $open->first();
        $surplus = 6000.0;
        $amount = round((float) $payOff->balance + $surplus, 2);

        $payment = Payment::create([
            'reference' => Payment::generateReference(),
            'tenant_id' => $tenant->id,
            'amount' => $amount,
            'currency' => 'EGP',
            'method' => 'cash',
            'status' => 'captured',
            'payment_date' => Carbon::now()->subDays(2),
            'notes' => 'Rent paid in advance — surplus held on account (credit).',
        ]);

        // Allocate only the invoice's balance; the surplus stays UNALLOCATED = the credit on account.
        $payment->invoices()->attach($payOff->id, ['allocated_amount' => round((float) $payOff->balance, 2)]);
        $payOff->recomputeTotals();

        $this->command->info("   Seeded EGP {$surplus} credit on account for {$tenant->name} (portal tenant1)");
    }

    /**
     * Create unpaid invoices with due dates spread across every AR aging
     * bucket so the dashboard chart shows a meaningful distribution.
     */
    private function seedArAgingSpread(): void
    {
        $now = Carbon::now();

        // negative days => due in the future (current bucket)
        $buckets = [
            ['days' => -10, 'count' => 2],  // current
            ['days' => 15,  'count' => 2],  // 1–30
            ['days' => 45,  'count' => 1],  // 31–60
            ['days' => 75,  'count' => 1],  // 61–90
            ['days' => 110, 'count' => 1],  // 90+
        ];

        $leases = Lease::where('status', 'active')->orderByRaw('(id * 17) % 101')->limit(10)->get();
        $i = 0;

        foreach ($buckets as $bucket) {
            for ($n = 0; $n < $bucket['count']; $n++) {
                if (! isset($leases[$i])) {
                    return;
                }
                $lease = $leases[$i++];
                $rent = (float) $lease->base_rent_monthly;
                $service = (float) $lease->service_charge_monthly;
                $subtotal = round($rent + $service, 2);
                $vat = round($service * 0.14, 2); // VAT only on service charge — base rent is VAT-exempt
                $total = round($subtotal + $vat, 2);
                $dueDate = $now->copy()->subDays($bucket['days']);

                $invoice = Invoice::create([
                    'number' => Invoice::generateNumber('AW', $dueDate),
                    'lease_id' => $lease->id,
                    'tenant_id' => $lease->tenant_id,
                    'status' => $bucket['days'] > 0 ? 'overdue' : 'issued',
                    'issue_date' => $dueDate->copy()->subDays(7),
                    'due_date' => $dueDate,
                    'period_start' => $dueDate->copy()->startOfMonth(),
                    'period_end' => $dueDate->copy()->endOfMonth(),
                    'subtotal' => $subtotal,
                    'vat_amount' => $vat,
                    'total' => $total,
                    'paid_amount' => 0,
                    'balance' => $total,
                    'currency' => 'EGP',
                ]);

                // Line items so the invoice renders (and posts to the GL) like a real one.
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Monthly Rent - '.$dueDate->format('F Y'),
                    'type' => 'base_rent', 'amount' => $rent, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => $rent,
                ]);
                InvoiceItem::create([
                    'invoice_id' => $invoice->id,
                    'description' => 'Service Charge - '.$dueDate->format('F Y'),
                    'type' => 'service_charge', 'amount' => $service, 'vat_rate' => Vat::standardRate(), 'vat_amount' => $vat, 'total' => round($service + $vat, 2),
                ]);
            }
        }

        $this->command->info('   Seeded AR aging spread across 5 buckets');
    }

    private function generateInvoiceHistory(Lease $lease, Tenant $tenant, float $rent, float $service, Carbon $startDate): void
    {
        $monthsToGenerate = (int) $startDate->diffInMonths(now());

        for ($m = 0; $m < $monthsToGenerate; $m++) {
            $period = $startDate->copy()->addMonths($m);
            $issueDate = $period->copy()->startOfMonth();
            $dueDate = $issueDate->copy()->addDays(7);

            $subtotal = $rent + $service;
            $vat = round($service * 0.14, 2); // VAT only on service charge
            $total = $subtotal + $vat;

            // Most are paid, ~15% overdue for the most recent ones
            $isPaid = ! ($m === $monthsToGenerate - 1 && rand(1, 100) > 70);
            $isPartial = ! $isPaid && rand(1, 100) > 50;

            $paidAmount = match (true) {
                $isPaid => $total,
                $isPartial => round($total * (rand(30, 70) / 100), 2),
                default => 0,
            };

            $invoice = Invoice::create([
                'number' => Invoice::generateNumber('AW', $issueDate),
                'lease_id' => $lease->id,
                'tenant_id' => $tenant->id,
                // paid_amount / balance / status are DERIVED — the allocation below plus
                // recomputeTotals() decides them, exactly as every real billing path does.
                // Seeding them by hand made this invoice look settled while its pivot was still
                // empty, so the first recompute (or the next seeder that looked for open AR)
                // saw an unpaid invoice and paid it a second time.
                'status' => 'issued',
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'period_start' => $period->copy()->startOfMonth(),
                'period_end' => $period->copy()->endOfMonth(),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => $total,
                'paid_amount' => 0,
                'balance' => $total,
                'currency' => 'EGP',
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Monthly Rent - '.$period->format('F Y'),
                'type' => 'base_rent',
                'amount' => $rent,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'total' => $rent,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Service Charge - '.$period->format('F Y'),
                'type' => 'service_charge',
                'amount' => $service,
                'vat_rate' => Vat::standardRate(),
                'vat_amount' => $vat,
                'total' => $service + $vat,
            ]);

            if ($paidAmount > 0) {
                $payment = Payment::create([
                    'reference' => Payment::generateReference(),
                    'tenant_id' => $tenant->id,
                    'amount' => $paidAmount,
                    'currency' => 'EGP',
                    'method' => $method = collect(['card', 'bank_transfer', 'instapay', 'cash'])->random(),
                    'bank_account_id' => $this->demoBankAccountFor($method, $invoice->id),
                    'status' => 'captured',
                    'payment_date' => $dueDate->copy()->subDays(rand(0, 5)),
                ]);

                $invoice->payments()->attach($payment->id, ['allocated_amount' => $paidAmount]);
                $invoice->recomputeTotals(); // → paid / partially_paid, balance from the pivot
            } elseif ($dueDate->isPast()) {
                $invoice->recomputeTotals(); // → overdue
            }
        }
    }

    private function calculateRent(array $unitData): float
    {
        $baseRate = match ($unitData['category']) {
            'food_beverage' => 700,
            'retail' => 550,
            'wellness' => 600,
            'service' => 450,
            'kiosk' => 800,
            default => 500,
        };

        return round($baseRate * $unitData['area'] / 100) * 100;
    }

    private function unitLayout(): array
    {
        $units = [];
        // Zone A — F&B and premium retail (front of walk)
        $zoneA = [
            ['code' => 'A-01', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 85],
            ['code' => 'A-02', 'floor' => 'Ground', 'category' => 'retail', 'area' => 60],
            ['code' => 'A-03', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 95],
            ['code' => 'A-04', 'floor' => 'Ground', 'category' => 'retail', 'area' => 55],
            ['code' => 'A-05', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 75],
            ['code' => 'A-06', 'floor' => 'Ground', 'category' => 'retail', 'area' => 50],
            ['code' => 'A-07', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 110],
            ['code' => 'A-08', 'floor' => 'Ground', 'category' => 'retail', 'area' => 65],
            ['code' => 'A-09', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 80],
            ['code' => 'A-10', 'floor' => 'Ground', 'category' => 'retail', 'area' => 70],
        ];
        // Zone B — Retail and Service
        $zoneB = [
            ['code' => 'B-01', 'floor' => 'Ground', 'category' => 'retail', 'area' => 55],
            ['code' => 'B-02', 'floor' => 'Ground', 'category' => 'retail', 'area' => 45],
            ['code' => 'B-03', 'floor' => 'Ground', 'category' => 'service', 'area' => 40],
            ['code' => 'B-04', 'floor' => 'Ground', 'category' => 'retail', 'area' => 60],
            ['code' => 'B-05', 'floor' => 'Ground', 'category' => 'wellness', 'area' => 90],
            ['code' => 'B-06', 'floor' => 'Ground', 'category' => 'retail', 'area' => 50],
            ['code' => 'B-07', 'floor' => 'Ground', 'category' => 'service', 'area' => 35],
            ['code' => 'B-08', 'floor' => 'Ground', 'category' => 'retail', 'area' => 70],
            ['code' => 'B-09', 'floor' => 'Ground', 'category' => 'retail', 'area' => 55],
            ['code' => 'B-10', 'floor' => 'Ground', 'category' => 'service', 'area' => 45],
            ['code' => 'B-11', 'floor' => '1', 'category' => 'retail', 'area' => 80],
            ['code' => 'B-12', 'floor' => '1', 'category' => 'retail', 'area' => 75],
            ['code' => 'B-13', 'floor' => '1', 'category' => 'wellness', 'area' => 120],
            ['code' => 'B-14', 'floor' => '1', 'category' => 'service', 'area' => 50],
            ['code' => 'B-15', 'floor' => '1', 'category' => 'retail', 'area' => 65],
        ];
        // Zone C — Wellness and F&B (back of walk)
        $zoneC = [
            ['code' => 'C-01', 'floor' => 'Ground', 'category' => 'wellness', 'area' => 180],
            ['code' => 'C-02', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 100],
            ['code' => 'C-03', 'floor' => 'Ground', 'category' => 'retail', 'area' => 55],
            ['code' => 'C-04', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 85],
            ['code' => 'C-05', 'floor' => 'Ground', 'category' => 'wellness', 'area' => 110],
            ['code' => 'C-06', 'floor' => 'Ground', 'category' => 'service', 'area' => 40],
            ['code' => 'C-07', 'floor' => 'Ground', 'category' => 'retail', 'area' => 60],
            ['code' => 'C-08', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 70],
            ['code' => 'C-09', 'floor' => '1', 'category' => 'wellness', 'area' => 150],
            ['code' => 'C-10', 'floor' => '1', 'category' => 'service', 'area' => 60],
            ['code' => 'C-11', 'floor' => '1', 'category' => 'retail', 'area' => 80],
            ['code' => 'C-12', 'floor' => '1', 'category' => 'food_beverage', 'area' => 95],
            ['code' => 'C-13', 'floor' => '1', 'category' => 'retail', 'area' => 65],
            ['code' => 'C-14', 'floor' => '1', 'category' => 'wellness', 'area' => 130],
            ['code' => 'C-15', 'floor' => '1', 'category' => 'service', 'area' => 45],
            ['code' => 'C-16', 'floor' => '1', 'category' => 'retail', 'area' => 70],
            ['code' => 'C-17', 'floor' => '1', 'category' => 'retail', 'area' => 55],
            ['code' => 'C-18', 'floor' => '1', 'category' => 'service', 'area' => 40],
            ['code' => 'C-19', 'floor' => '1', 'category' => 'kiosk', 'area' => 15],
            ['code' => 'C-20', 'floor' => '1', 'category' => 'kiosk', 'area' => 12],
            ['code' => 'C-21', 'floor' => '1', 'category' => 'retail', 'area' => 60],
            ['code' => 'C-22', 'floor' => '1', 'category' => 'retail', 'area' => 50],
            ['code' => 'C-23', 'floor' => '1', 'category' => 'service', 'area' => 35],
            ['code' => 'C-24', 'floor' => '1', 'category' => 'retail', 'area' => 70],
            ['code' => 'C-25', 'floor' => '1', 'category' => 'kiosk', 'area' => 18],
        ];

        return array_merge($zoneA, $zoneB, $zoneC);
    }

    /**
     * Tenant roster — recognizable Egyptian retail / F&B / service brands,
     * ordered to line up with the unit layout's category per index (Zone A
     * front F&B + retail, Zone B retail/service/wellness, Zone C wellness +
     * F&B). The first three (Cilantro, Magrabi Optical, Buffalo Burger) get
     * portal logins and drive the seeded maintenance demo, so keep them first.
     */
    private function tenantList(): array
    {
        return [
            // Zone A — front of walk (F&B + premium retail)
            ['name' => 'Cilantro', 'legal' => 'Cilantro Café Egypt LLC', 'contact' => 'Ahmed Hassan'],          // A-01 F&B (portal)
            ['name' => 'Magrabi Optical', 'legal' => 'Magrabi Optical Egypt LLC', 'contact' => 'Mona Sherif'],  // A-02 retail (portal)
            ['name' => 'Buffalo Burger', 'legal' => 'Buffalo Burger Egypt LLC', 'contact' => 'Karim Adel'],     // A-03 F&B (portal)
            ['name' => 'Seif Pharmacy', 'legal' => 'Seif Pharmacies LLC', 'contact' => 'Dr. Sara Mahmoud'],     // A-04 retail
            ['name' => 'Tseppas', 'legal' => 'Tseppas Patisserie LLC', 'contact' => 'Nermeen Fouad'],           // A-05 F&B
            ['name' => 'Concrete', 'legal' => 'Concrete Menswear LLC', 'contact' => 'Nada Fahmy'],              // A-06 retail
            ['name' => 'Abou El Sid', 'legal' => 'Abou El Sid Restaurants LLC', 'contact' => 'Hossam Darwish'], // A-07 F&B
            ['name' => 'Mobaco', 'legal' => 'Mobaco Cotton LLC', 'contact' => 'Layla Mostafa'],                 // A-08 retail
            ['name' => 'Zööba', 'legal' => 'Zooba Egyptian Eatery LLC', 'contact' => 'Marwan Adel'],            // A-09 F&B
            ['name' => 'B.TECH', 'legal' => 'B.TECH Egypt LLC', 'contact' => 'Tarek Saad'],                     // A-10 retail
            // Zone B — retail, services, wellness
            ['name' => 'Town Team', 'legal' => 'Town Team Apparel LLC', 'contact' => 'Rania Habib'],            // B-01 retail
            ['name' => 'Diwan Bookstore', 'legal' => 'Diwan Bookstores LLC', 'contact' => 'Omar El-Sayed'],     // B-02 retail
            ['name' => 'Spotless Dry Cleaners', 'legal' => 'Spotless Laundry LLC', 'contact' => 'Hassan Aly'],  // B-03 service
            ['name' => 'Carina', 'legal' => 'Carina Wear LLC', 'contact' => 'Heba Mostafa'],                    // B-04 retail
            ['name' => 'Smart Gym', 'legal' => 'Smart Gym Fitness LLC', 'contact' => 'Coach Mido'],             // B-05 wellness
            ['name' => 'Mihyar', 'legal' => 'Mihyar Fashion LLC', 'contact' => 'Yara Wahby'],                   // B-06 retail
            ['name' => 'Fawry Plus', 'legal' => 'Fawry Banking Services LLC', 'contact' => 'Ibrahim Naguib'],   // B-07 service
            ['name' => 'Dandy Mega Store', 'legal' => 'Dandy Retail LLC', 'contact' => 'Dina Rashed'],          // B-08 retail
            ['name' => '2B Computers', 'legal' => '2B Egypt LLC', 'contact' => 'Khaled Yousef'],                // B-09 retail
            ['name' => "Gentlemen's Barber", 'legal' => 'Gentlemen Grooming LLC', 'contact' => 'Sherif Eldin'], // B-10 service
            ['name' => 'Mobica', 'legal' => 'Mobica Furniture LLC', 'contact' => 'Marwa Salem'],               // B-11 retail
            ['name' => 'El Araby Home', 'legal' => 'El Araby Group LLC', 'contact' => 'Amr Kamel'],             // B-12 retail
            ['name' => 'El Ezaby Pharmacy', 'legal' => 'El Ezaby Pharmacies LLC', 'contact' => 'Dr. Sherif Hany'], // B-13 wellness
            ['name' => 'Bosta Pickup Point', 'legal' => 'Bosta Logistics LLC', 'contact' => 'Wael Hosni'],      // B-14 service
            ['name' => 'Kazyon Market', 'legal' => 'Kazyon Retail LLC', 'contact' => 'Hala Ismail'],            // B-15 retail
            // Zone C — wellness + F&B (back of walk)
            ['name' => 'California Gym', 'legal' => 'California Fitness Egypt LLC', 'contact' => 'Coach Sherif'], // C-01 wellness
            ['name' => 'Cook Door', 'legal' => 'Cook Door Egypt LLC', 'contact' => 'Ali Mahmoud'],              // C-02 F&B
            ['name' => 'Seoudi Market', 'legal' => 'Seoudi Supermarket LLC', 'contact' => 'Fatma Zaki'],        // C-03 retail
            ['name' => "Mo'men", 'legal' => 'Momen Group LLC', 'contact' => 'Mostafa Lotfy'],                   // C-04 F&B
            ['name' => 'Cleopatra Wellness Spa', 'legal' => 'Cleopatra Spa LLC', 'contact' => 'Maya Salah'],    // C-05 wellness
            ['name' => 'Crystal Laundry', 'legal' => 'Crystal Care LLC', 'contact' => 'Wael Sobhy'],           // C-06 service
            ['name' => 'Tradeline', 'legal' => 'Tradeline Stores LLC', 'contact' => 'Ramy Adel'],              // C-07 retail
            ['name' => 'Gad Restaurant', 'legal' => 'Gad Foods LLC', 'contact' => 'Chef Hossam'],              // C-08 F&B
            // Remaining units stay vacant for realistic occupancy
        ];
    }

    /**
     * Seed realistic vendors for the maintenance + supplier side of the business.
     * Each gets a primary contact and (where it makes sense) an active service contract
     * against Atriom Walk.
     */
    private function seedVendors(Asset $asset): void
    {
        $vendors = [
            [
                'name' => 'Cool-Air HVAC Services',
                'type' => 'contractor',
                'tax_id' => 'EG-410-882-001',
                'email' => 'ops@cool-air.eg',
                'locale' => 'ar',
                'phone' => '+201112223344',
                'city' => 'Cairo',
                'contact' => ['name' => 'Ahmed Saleh', 'role' => 'Operations Lead', 'phone' => '+201112223344'],
                'contract' => ['name' => 'HVAC maintenance — annual', 'value' => 360000, 'start' => '2026-01-01', 'end' => '2026-12-31', 'penalty_basis' => 'per_day', 'penalty_rate' => 500],
            ],
            [
                'name' => 'BrightSpark Electrical',
                'type' => 'contractor',
                'tax_id' => 'EG-410-882-002',
                'email' => 'service@brightspark.eg',
                'phone' => '+201233445566',
                'city' => 'Giza',
                'contact' => ['name' => 'Mona Atef', 'role' => 'Service Manager', 'phone' => '+201233445566'],
                'contract' => ['name' => 'Common-area electrical upkeep', 'value' => 180000, 'start' => '2026-01-01', 'end' => '2026-12-31'],
            ],
            [
                'name' => 'PureWater Plumbing',
                'type' => 'service_provider',
                'email' => 'help@purewater.eg',
                'phone' => '+201556677889',
                'city' => 'Cairo',
                'contact' => ['name' => 'Karim El-Gohary', 'role' => 'Owner', 'phone' => '+201556677889'],
                'contract' => ['name' => 'On-call plumbing — SLA', 'value' => 90000, 'start' => '2026-01-01', 'end' => '2026-12-31', 'penalty_basis' => 'flat', 'penalty_rate' => 1500],
            ],
            [
                'name' => 'CleanFleet Janitorial',
                'type' => 'service_provider',
                'tax_id' => 'EG-410-882-004',
                'email' => 'contact@cleanfleet.eg',
                'phone' => '+201001112233',
                'city' => 'Cairo',
                'contact' => ['name' => 'Hala Mustafa', 'role' => 'Account Manager', 'phone' => '+201001112233'],
                'contract' => ['name' => 'Daily cleaning + waste handling', 'value' => 480000, 'start' => '2026-01-01', 'end' => '2026-12-31'],
            ],
            [
                'name' => 'SecureGuard Security',
                'type' => 'service_provider',
                'tax_id' => 'EG-410-882-005',
                'email' => 'ops@secureguard.eg',
                'phone' => '+201224455667',
                'city' => 'Cairo',
                'contact' => ['name' => 'Mahmoud Sayed', 'role' => 'Site Supervisor', 'phone' => '+201224455667'],
                'contract' => ['name' => 'Mall security — 24/7', 'value' => 720000, 'start' => '2026-01-01', 'end' => '2026-12-31'],
            ],
            [
                'name' => 'GreenLeaf Landscaping',
                'type' => 'supplier',
                'email' => 'info@greenleaf.eg',
                'phone' => '+201117788990',
                'city' => 'Cairo',
                'contact' => ['name' => 'Sara Adel', 'role' => 'Sales', 'phone' => '+201117788990'],
                'contract' => null,
                // Deliberately EXPIRED — demonstrates the COI gate blocking a work-order assignment.
                'coi' => ['expires' => now()->subMonths(2)->toDateString(), 'insurer' => 'GIG Egypt', 'policy' => 'POL-GL-2025'],
            ],
            [
                'name' => 'PestStop Egypt',
                'type' => 'service_provider',
                'email' => 'support@peststop.eg',
                'locale' => 'ar',
                'phone' => '+201557788992',
                'city' => 'Cairo',
                'contact' => ['name' => 'Tarek Sami', 'role' => 'Operations', 'phone' => '+201557788992'],
                'contract' => ['name' => 'Quarterly pest control', 'value' => 60000, 'start' => '2026-01-01', 'end' => '2026-12-31'],
                // No certificate on file at all — also blocked from assignment.
                'coi' => null,
            ],
            [
                'name' => 'FireSafe Consultants',
                'type' => 'consultant',
                'email' => 'audit@firesafe.eg',
                'phone' => '+201339988776',
                'city' => 'Alexandria',
                'contact' => ['name' => 'Eng. Hisham Fahmy', 'role' => 'Lead Consultant', 'phone' => '+201339988776'],
                'contract' => ['name' => 'Annual fire-safety audit + drills', 'value' => 120000, 'start' => '2026-01-01', 'end' => '2026-12-31'],
            ],
        ];

        foreach ($vendors as $v) {
            // Most vendors carry a valid certificate of insurance (8 months out); the two with an
            // explicit 'coi' key are non-compliant (expired / missing) to demo the assignment gate.
            $coi = array_key_exists('coi', $v)
                ? $v['coi']
                : ['expires' => now()->addMonths(8)->toDateString(), 'insurer' => 'Misr Insurance', 'policy' => 'POL-'.strtoupper(substr(md5($v['email']), 0, 8))];

            $vendor = Vendor::updateOrCreate(
                ['email' => $v['email']],
                [
                    'name' => $v['name'],
                    'type' => $v['type'],
                    'status' => 'active',
                    'tax_id' => $v['tax_id'] ?? null,
                    'phone' => $v['phone'],
                    'city' => $v['city'],
                    // Which language this supplier's purchase orders and withholding certificates
                    // are written in. Stated on some and not others, for the reason given on the
                    // tenant block above.
                    'locale' => $v['locale'] ?? null,
                ],
            );

            // Compliance file. Insurance is the blocking document (a lapsed one removes the vendor
            // from every assignment picker); the statutory Egyptian documents are chased but never
            // block site work. One vendor's tax card is deliberately near expiry to demo the chase.
            if (($coi['expires'] ?? null) !== null) {
                VendorDocument::updateOrCreate(
                    ['vendor_id' => $vendor->id, 'type' => VendorDocument::TYPE_INSURANCE_COI],
                    [
                        'reference' => $coi['policy'] ?? null,
                        'issuer' => $coi['insurer'] ?? null,
                        'expires_on' => $coi['expires'],
                    ],
                );
            }

            VendorDocument::updateOrCreate(
                ['vendor_id' => $vendor->id, 'type' => VendorDocument::TYPE_TAX_CARD],
                [
                    'reference' => $v['tax_id'] ?? null,
                    'issuer' => 'ETA',
                    'expires_on' => now()->addDays(str_contains($v['name'], 'Janitorial') ? 12 : 400)->toDateString(),
                ],
            );

            VendorDocument::updateOrCreate(
                ['vendor_id' => $vendor->id, 'type' => VendorDocument::TYPE_COMMERCIAL_REGISTER],
                [
                    'reference' => 'CR-'.strtoupper(substr(md5($v['email']), 0, 6)),
                    'expires_on' => now()->addMonths(20)->toDateString(),
                ],
            );

            VendorContact::updateOrCreate(
                ['vendor_id' => $vendor->id, 'name' => $v['contact']['name']],
                [
                    'role' => $v['contact']['role'],
                    'email' => $v['email'],
                    'phone' => $v['contact']['phone'],
                    'is_primary' => true,
                    // A contractor who can actually SIGN IN. The `/vendor` panel shipped with four
                    // verbs and no demo login, so the whole thing looked unbuilt on a fresh demo —
                    // the same reason demo data is treated as part of a feature here rather than as
                    // decoration. Password `password`, like every other demo account.
                    'is_portal_user' => true,
                    'password' => 'password',
                ],
            );

            if ($v['contract']) {
                // Every real contract has a renewal-notice window. One cleaning contract's end
                // date is pulled close so its notice deadline is already past — lighting up the
                // "Renewal notice due" card + filter on a fresh demo. Auto-renewing ones make the
                // deadline urgent (silence = another term); fixed ones just end.
                $isSoftService = str_contains($v['name'], 'Janitorial') || str_contains($v['name'], 'Security');
                $endDate = str_contains($v['name'], 'Janitorial')
                    ? now()->addDays(20)->toDateString()   // inside a 90-day notice window → due now
                    : $v['contract']['end'];

                $contract = VendorContract::updateOrCreate(
                    ['vendor_id' => $vendor->id, 'name' => $v['contract']['name']],
                    [
                        'asset_id' => $asset->id,
                        'status' => 'active',
                        'start_date' => $v['contract']['start'],
                        'end_date' => $endDate,
                        'notice_period_days' => $isSoftService ? 90 : 30,
                        'auto_renews' => $isSoftService,
                        'value' => $v['contract']['value'],
                        'currency' => 'EGP',
                        // FR-CM-08 — SLA penalty terms, if this contract negotiated any.
                        // Per-day is the accruing basis, and the reason the penalty is
                        // re-assessed on every scan rather than computed once.
                        'sla_penalty_basis' => $v['contract']['penalty_basis'] ?? 'none',
                        'sla_penalty_rate' => $v['contract']['penalty_rate'] ?? 0,
                    ],
                );

                // A demo change order on the cleaning contract, so "committed vs as-amended" and
                // the amendments audit trail aren't empty on a fresh install.
                if (str_contains($v['name'], 'Janitorial')) {
                    VendorContractAmendment::updateOrCreate(
                        ['vendor_contract_id' => $contract->id, 'reference' => 'CO-01'],
                        [
                            'value_delta' => round($v['contract']['value'] * 0.15, 2),
                            'effective_on' => now()->subMonths(2)->toDateString(),
                            'reason' => 'Added evening deep-clean round for the food court',
                        ],
                    );
                }
            }
        }

        $this->command->info('   Vendors seeded: '.Vendor::count());
    }

    /**
     * Seed a handful of credit notes across different statuses so the
     * CN list isn't empty on a fresh demo and each workflow stage is
     * represented (draft / issued with balance / partially applied / void).
     */
    private function seedCreditNotes(): void
    {
        // Pick three real invoices to attach the credit notes to
        $invoices = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'paid'])
            ->with('lease.tenant')
            ->orderByRaw('(id * 17) % 101')
            ->limit(4)
            ->get();

        if ($invoices->count() < 3) {
            return;
        }

        // 1) Draft — admin still drafting, not yet issued
        $draft = $this->makeCreditNote($invoices[0], 1200, 'adjustment', 'Goodwill adjustment for prolonged AC outage in February.');
        $draft->status = 'draft';
        $draft->save();

        // 2) Issued with balance remaining — ready to apply
        $issued = $this->makeCreditNote($invoices[1], 2500, 'dispute', 'Service-charge dispute settled in tenant favor for one month.');
        $issued->status = 'issued';
        $issued->save();

        // 3) Partially applied — issued, then half consumed against an OPEN invoice
        // via the real service so both sides stay consistent (the note's
        // applied_amount AND the invoice's credit_applied_amount move together —
        // otherwise the ledger tie-out flags phantom drift). Target the
        // highest-balance PAYABLE invoice so the application actually lands.
        $applyTarget = Invoice::whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->where('balance', '>', 0)
            ->orderByDesc('balance')
            ->first();
        if ($applyTarget) {
            $partial = $this->makeCreditNote($applyTarget, 4000, 'return', 'Stock return processed for non-trading promotional fixture.');
            $service = app(CreditNoteService::class);
            $service->issue($partial);
            $service->applyToInvoice($partial, $applyTarget, 2000);
        }

        // 4) Void — refused / cancelled before application
        if (isset($invoices[3])) {
            $void = $this->makeCreditNote($invoices[3], 800, 'other', 'Issued in error; voided same day.');
            $void->status = 'void';
            $void->voided_at = now()->subDay();
            $void->balance = 0;
            $void->save();
        }

        $this->command->info('   Credit notes seeded: '.CreditNote::count());
    }

    private function makeCreditNote(Invoice $invoice, float $total, string $reason, string $description): CreditNote
    {
        $note = CreditNote::create([
            'tenant_id' => $invoice->tenant_id,
            'invoice_id' => $invoice->id,
            'lease_id' => $invoice->lease_id,
            'status' => 'draft',
            'issue_date' => now()->subDays(rand(1, 14)),
            'reason' => $reason,
            'reason_notes' => $description,
            'subtotal' => $total,
            'vat_amount' => 0,
            'total' => $total,
            'applied_amount' => 0,
            'balance' => $total,
            'currency' => 'EGP',
            'issued_by_user_id' => 1,
        ]);

        CreditNoteItem::create([
            'credit_note_id' => $note->id,
            'description' => $description,
            'amount' => $total,
            'vat_rate' => 0,
            'vat_amount' => 0,
            'total' => $total,
        ]);

        return $note->refresh();
    }

    /**
     * Assign the demo staff users (manager, leasing, maintenance) to Atriom Walk
     * so the new asset_user pivot has realistic data on first boot.
     */
    private function seedStaffAssignments(Asset $asset): void
    {
        // Which staff logins may operate on this property. A job title is deliberately NOT stored
        // here — `employees.position` already models "who works here and as what", and the pivot
        // column that used to duplicate it was never read by anything.
        $emails = [
            'manager@mall.test',
            'leasing@mall.test',
            'operations@mall.test',
            'accounting@mall.test',
            'marketing@mall.test',
            'hr@mall.test',
            // The auditor was the one demo login with no property, so every page 404'd for it
            // and the role was untestable. (The owner reaches the property through asset_owner,
            // not this pivot, so it stays out of this list.)
            'viewer@mall.test',
        ];

        foreach ($emails as $email) {
            $user = User::where('email', $email)->first();
            if (! $user) {
                continue;
            }

            $asset->staff()->syncWithoutDetaching([
                $user->id => ['assigned_at' => now()->subMonths(6)],
            ]);
        }

        $this->command->info('   Staff assignments seeded: '.$asset->staff()->count());
    }

    /**
     * A few marketing spends per budget so the fund shows accrued (from billed
     * levies) − spent = a live balance. Spends ~40% of the accrued fund, leaving
     * a healthy positive balance. recomputeSpent fires via MarketingSpend's hook.
     */
    private function seedMarketingSpends(): void
    {
        $marketingLead = User::where('email', 'marketing@mall.test')->first();

        $samples = [
            ['category' => 'offer', 'description' => 'Seasonal storefront offer campaign', 'frac' => 0.20],
            ['category' => 'event', 'description' => 'Weekend family activation', 'frac' => 0.12],
            ['category' => 'printed_work', 'description' => 'Directory + signage reprint', 'frac' => 0.08],
        ];

        foreach (MarketingBudget::all() as $budget) {
            foreach ($samples as $i => $s) {
                $amount = round((float) $budget->accrued_amount * $s['frac'], 2);
                if ($amount <= 0) {
                    continue;
                }
                MarketingSpend::create([
                    'marketing_budget_id' => $budget->id,
                    'category' => $s['category'],
                    'description' => $s['description'],
                    'amount' => $amount,
                    'paid_from' => 'cash',
                    'spent_on' => now()->subDays(($i + 1) * 10),
                    'created_by_user_id' => $marketingLead?->id,
                ]);
            }
        }
    }

    /**
     * The shopper feed (module 36): a store directory plus a feed with one card in each
     * interesting state.
     *
     * **No artwork is seeded, deliberately.** Faking hero images would mean either shipping
     * binaries in the repo or generating them on every reseed, and the demo's job is to show the
     * SHAPE of the data. The consequence is honest and visible: the published rows below are
     * `published` in the register, and a visitor-app screenshot shows cards with no image — which
     * is exactly what an operator would see if they published without artwork, and a prompt to
     * upload one. (`PublishMarketingPostService` refuses that through the UI; these rows are
     * written directly, which is the seeder's licence and not a hole in the guard.)
     *
     * The states are chosen so every tab and badge on the admin screen has something in it: a
     * featured live offer, a second live offer, a mall-wide event, a retailer submission
     * AWAITING REVIEW (so the nav badge shows 1 on a fresh install), one the mall returned with a
     * reason, and an expired one the hourly sweep will archive.
     */
    private function seedMarketingPosts(Asset $asset): void
    {
        $marketingLead = User::where('email', 'marketing@mall.test')->first();

        // The store directory half: give the demo tenants a shopper-facing identity. Without it
        // every offer card renders under a billing name, which is the exact failure the
        // trade_name column exists to prevent — so the demo would misrepresent the feature.
        //
        // Categories are MAPPED, not generated. The first cut assigned them round-robin, which
        // produced a directory listing Abou El Sid (a Cairo institution) under "sports" and
        // Buffalo Burger under "electronics" — data that is not merely arbitrary but visibly
        // wrong, and a demo whose category filter returns nonsense teaches an operator to
        // distrust the filter rather than the fixture. The roster is a fixed set of real Egyptian
        // brands, so the honest thing is to name each one.
        //
        // Arabic trade names are here for the same reason: the bilingual columns are a headline
        // feature of this module, and a demo where every `nameAr` is null demonstrates nothing.
        $directory = [
            'Cilantro' => ['food_beverage', 'سيلانترو'],
            'Magrabi Optical' => ['health_beauty', 'مغربي للبصريات'],
            'Buffalo Burger' => ['food_beverage', 'بافلو برجر'],
            'Seif Pharmacy' => ['health_beauty', 'صيدليات سيف'],
            'Tseppas' => ['food_beverage', 'تسيباس'],
            'Concrete' => ['fashion', 'كونكريت'],
            'Abou El Sid' => ['food_beverage', 'أبو السيد'],
            'Mobaco' => ['fashion', 'موباكو'],
            'Zööba' => ['food_beverage', 'زوبا'],
            'B.TECH' => ['electronics', 'بي تك'],
            'Town Team' => ['fashion', 'تاون تيم'],
            'Diwan Bookstore' => ['entertainment', 'مكتبة ديوان'],
            'Spotless Dry Cleaners' => ['services', 'سبوتلس للتنظيف الجاف'],
            'Carina' => ['fashion', 'كارينا'],
            'Smart Gym' => ['sports', 'سمارت جيم'],
            'Mihyar' => ['fashion', 'مهيار'],
            'Fawry Plus' => ['services', 'فوري بلس'],
            'Dandy Mega Store' => ['hypermarket', 'داندي ميجا ستور'],
            '2B Computers' => ['electronics', 'تو بي للكمبيوتر'],
            "Gentlemen's Barber" => ['health_beauty', 'جنتلمِن باربر'],
            'Mobica' => ['home_lifestyle', 'موبيكا'],
            'El Araby Home' => ['home_lifestyle', 'العربي هوم'],
            'El Ezaby Pharmacy' => ['health_beauty', 'صيدليات العزبي'],
            'Bosta Pickup Point' => ['services', 'نقطة استلام بوسطة'],
            'Kazyon Market' => ['hypermarket', 'كازيون'],
            'California Gym' => ['sports', 'كاليفورنيا جيم'],
            'Cook Door' => ['food_beverage', 'كوك دور'],
            'Seoudi Market' => ['hypermarket', 'سعودي ماركت'],
            "Mo'men" => ['food_beverage', 'مؤمن'],
            'Cleopatra Wellness Spa' => ['health_beauty', 'كليوباترا سبا'],
            'Crystal Laundry' => ['services', 'كريستال لاندري'],
            'Tradeline' => ['electronics', 'تريدلاين'],
            'Gad Restaurant' => ['food_beverage', 'مطاعم جاد'],
        ];

        $tenants = Tenant::query()
            ->whereHas('activeLeases.units', fn ($q) => $q->where('units.asset_id', $asset->id))
            ->orderBy('id')
            ->get();

        foreach ($tenants as $tenant) {
            [$category, $nameAr] = $directory[$tenant->name] ?? [null, null];

            // A tenant not on the roster (one added by another seeder later) is listed under its
            // own name with NO category rather than a guessed one — "uncategorised" is a true
            // statement about the data; "sports" would not be.
            $tenant->forceFill([
                'trade_name' => $tenant->name,
                'trade_name_ar' => $nameAr,
                'retail_category' => $category,
                'public_description' => $category === null
                    ? null
                    : __('admin.retail_categories.'.$category).' at '.$asset->name.'.',
                'is_listed' => true,
            ])->save();
        }

        if ($tenants->isEmpty()) {
            return; // No trading tenants — nothing a shopper could be shown.
        }

        // Attach each offer to a store it would plausibly come from, resolved BY NAME with a
        // fallback. Position-based picking ("the first two tenants") put a back-to-school sale on
        // a coffee shop — the module works either way, but a demo is also a sales tool, and an
        // incoherent one invites the reader to doubt the parts they cannot check.
        $store = fn (string $name) => $tenants->firstWhere('name', $name) ?? $tenants->first();

        $cafe = $store('Cilantro');
        $fashion = $store('Concrete');
        $bookshop = $store('Diwan Bookstore');

        $post = function (array $attrs) use ($asset, $marketingLead): MarketingPost {
            // view_count / click_count are NOT fillable — they are server-managed counters that
            // the app only ever moves with a builder increment, so `create()` would silently drop
            // them and every demo card would read 0. Split them out and write them the same way
            // the app does, rather than widening $fillable and handing a client a way to set them.
            $counters = array_intersect_key($attrs, array_flip(['view_count', 'click_count']));
            $attrs = array_diff_key($attrs, $counters);

            $post = MarketingPost::create(array_merge([
                'asset_id' => $asset->id,
                'created_by' => $marketingLead?->id,
                'type' => MarketingPost::TYPE_OFFER,
                'audience' => MarketingPost::AUDIENCE_VISITORS,
            ], $attrs));

            if ($counters !== []) {
                MarketingPost::query()->whereKey($post->getKey())->update($counters);
                $post->refresh();
            }

            return $post;
        };

        $post([
            'tenant_id' => $cafe->id,
            'title' => '20% off all coffee, all week',
            'title_ar' => 'خصم ٢٠٪ على كل القهوة طوال الأسبوع',
            'summary' => 'Every hot and iced drink, dine-in or takeaway.',
            'discount_label' => '20% OFF',
            'discount_label_ar' => 'خصم ٢٠٪',
            'terms' => 'Excludes beans and merchandise. One per customer per visit.',
            'status' => MarketingPost::STATUS_PUBLISHED,
            'published_at' => now()->subDays(2),
            'starts_at' => now()->subDays(2),
            'ends_at' => now()->addDays(5),
            'is_featured' => true,
            'priority' => 100,
            'view_count' => 1_284,
            'click_count' => 96,
        ]);

        $post([
            'tenant_id' => $fashion->id,
            'title' => 'Buy one, get one — new season',
            'title_ar' => 'اشترِ قطعة واحصل على الأخرى — الموسم الجديد',
            'discount_label' => 'BUY 1 GET 1',
            'status' => MarketingPost::STATUS_PUBLISHED,
            'published_at' => now()->subDay(),
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(12),
            'view_count' => 412,
            'click_count' => 31,
        ]);

        // Mall-wide: no store behind it. Shows the null-tenant path on the card.
        $post([
            'tenant_id' => null,
            'type' => MarketingPost::TYPE_EVENT,
            'title' => 'Late-night shopping every Thursday',
            'title_ar' => 'تسوّق حتى وقت متأخر كل خميس',
            'summary' => 'Doors open until midnight through the season.',
            'status' => MarketingPost::STATUS_PUBLISHED,
            'published_at' => now()->subDays(5),
            'starts_at' => now()->subDays(5),
            // Open-ended: exercises the null-boundary branch of the visibility predicate.
            'ends_at' => null,
            'view_count' => 2_610,
        ]);

        // The review queue — one waiting, so the nav badge reads 1 on a fresh install.
        // created_by null is what marks it retailer-authored.
        $post([
            'tenant_id' => $fashion->id,
            'created_by' => null,
            'title' => 'Flash sale this Friday — up to 50% off',
            'title_ar' => 'تخفيضات الجمعة — حتى ٥٠٪',
            'discount_label' => 'UP TO 50% OFF',
            'status' => MarketingPost::STATUS_PENDING,
            'starts_at' => now()->addDays(3),
            'ends_at' => now()->addDays(4),
        ]);

        // Returned to the retailer WITH a reason — the thing that stops the resubmit loop.
        $post([
            'tenant_id' => $cafe->id,
            'created_by' => null,
            'title' => 'Free pastry with every coffee',
            'status' => MarketingPost::STATUS_REJECTED,
            'reviewed_by' => $marketingLead?->id,
            'reviewed_at' => now()->subDays(3),
            'review_notes' => 'The artwork is low-resolution and the end date is missing. Please resubmit with a 16:9 image and a closing date.',
            'starts_at' => now()->subDays(4),
        ]);

        // Past its window: what `marketing:expire-posts` archives on its next hourly run.
        $post([
            'tenant_id' => $bookshop->id,
            'title' => 'Back to school — 15% off',
            'status' => MarketingPost::STATUS_PUBLISHED,
            'published_at' => now()->subDays(40),
            'starts_at' => now()->subDays(40),
            'ends_at' => now()->subDays(9),
            'view_count' => 733,
            'click_count' => 58,
        ]);
    }

    /**
     * Mall news (module 27): the operator's notice board, in all three of its states.
     *
     * Every notice is written in BOTH languages, because a demo where the Arabic column is empty
     * teaches the operator that it is optional — and the one thing this module changed for a
     * market whose tenants read Arabic is that it no longer is.
     *
     * The sent ones go through {@see SendAnnouncementAction} rather than being hand-stamped, so
     * the recipient rows, the `notified_at` stamps and the bell/push fan-out are the real ones.
     * A few read receipts are then stamped so the admin table's read rate shows something other
     * than "0 / n" — which is what the screen looks like the day it ships and reads like a bug.
     */
    /**
     * The house-rules register with a tariff on it, and four breaches recorded against it.
     *
     * Demo data is part of the feature: with none of this, `/admin/violation-categories` shows seven
     * rules with a blank fine and `/admin/violations` is empty, so the register, the standard-fine
     * prefill and the "bill the fine" action all read as unbuilt to anyone opening the demo. The
     * SEEDER deliberately ships no tariff — a schedule of penalties is the operator's handbook and
     * inventing figures on a real install would put numbers in front of a field officer that nobody
     * agreed. A DEMO mall is exactly where the figures should exist.
     */
    /**
     * A couple of the operator's own fields, answered on real tenants (D-7).
     *
     * Same reasoning as the violation tariff above: with no definitions the Custom Fields screen is
     * empty, no record shows an "Additional information" section, and the whole capability reads as
     * unbuilt to anyone opening the demo. The install ships NONE — what an operator records about a
     * tenant is their vocabulary and inventing it on a real deployment would put fields in front of
     * their staff that nobody asked for. A DEMO mall is exactly where they should exist.
     */
    private function seedCustomFields(): void
    {
        CustomField::create([
            'model' => 'tenant',
            'key' => 'parent_group',
            'label_en' => 'Parent buying group',
            'label_ar' => 'المجموعة الشرائية الأم',
            'type' => 'text',
            'sort_order' => 1,
        ]);

        CustomField::create([
            'model' => 'tenant',
            'key' => 'segment',
            'label_en' => 'Segment',
            'label_ar' => 'القطاع',
            'type' => 'select',
            'sort_order' => 2,
            'options' => [
                ['value' => 'f_and_b', 'label_en' => 'Food & beverage', 'label_ar' => 'أغذية ومشروبات'],
                ['value' => 'fashion', 'label_en' => 'Fashion', 'label_ar' => 'أزياء'],
                ['value' => 'services', 'label_en' => 'Services', 'label_ar' => 'خدمات'],
            ],
        ]);

        CustomField::create([
            'model' => 'unit',
            'key' => 'shutter_type',
            'label_en' => 'Shutter type',
            'label_ar' => 'نوع الستارة المعدنية',
            'type' => 'text',
        ]);

        // Answered on a few, not all — a column that is filled in everywhere hides the fact that
        // these are optional, and the "not answered" case is the one a reader needs to recognise.
        $answers = [
            ['parent_group' => 'Americana Group', 'segment' => 'f_and_b'],
            ['parent_group' => 'Alshaya', 'segment' => 'fashion'],
            ['segment' => 'services'],
        ];

        Tenant::query()->where('party_type', 'retailer')->orderBy('id')->take(3)->get()
            ->each(function (Tenant $tenant, int $i) use ($answers): void {
                $tenant->fillCustomFields($answers[$i])->save();
            });
    }

    private function seedViolations(Asset $asset): void
    {
        $tariff = [
            'safety' => 5000,
            'unauthorized_works' => 3000,
            'signage' => 1500,
            'operating_hours' => 1000,
            'cleanliness' => 750,
            'noise' => 500,
        ];

        foreach ($tariff as $code => $fine) {
            ViolationCategory::query()->where('code', $code)->update(['default_fine_amount' => $fine]);
        }

        ViolationCategory::flushCatalogue();

        $officer = User::where('email', 'operations@mall.test')->first();
        $tenants = Tenant::query()->where('party_type', 'retailer')->orderBy('id')->take(4)->get();

        if ($tenants->count() < 4) {
            return;
        }

        $rows = [
            // Open, unbilled, with the standard fine — the ordinary case a field officer records.
            ['category' => 'signage', 'days' => 6, 'fine' => 1500, 'status' => Violation::STATUS_OPEN,
                'description' => 'Pull-up banner placed outside the demise line, blocking the mall walkway.'],
            // Open, notified, no fine — a warning first, which is most of the rule book in practice.
            ['category' => 'cleanliness', 'days' => 11, 'fine' => null, 'status' => Violation::STATUS_OPEN,
                'notified' => true, 'description' => 'Back-of-house corridor left with uncollected packaging overnight.'],
            // Resolved — the shop fixed it, so the record explains a fine that was never charged.
            ['category' => 'operating_hours', 'days' => 24, 'fine' => 1000, 'status' => Violation::STATUS_RESOLVED,
                'notified' => true, 'description' => 'Shutters down 40 minutes before mall closing on three consecutive days.'],
            // The one that gets BILLED below — a safety breach at the top of the tariff.
            ['category' => 'safety', 'days' => 18, 'fine' => 5000, 'status' => Violation::STATUS_OPEN,
                'notified' => true, 'description' => 'Rear fire exit obstructed by stock pallets.'],
        ];

        $billable = null;

        foreach ($rows as $i => $row) {
            $violation = Violation::create([
                'asset_id' => $asset->id,
                'tenant_id' => $tenants[$i]->id,
                'category' => $row['category'],
                'description' => $row['description'],
                'fine_amount' => $row['fine'],
                'violation_date' => now()->subDays($row['days'])->toDateString(),
                'status' => $row['status'],
                'notified_at' => ($row['notified'] ?? false) ? now()->subDays($row['days'] - 1) : null,
                'created_by_user_id' => $officer?->id,
            ]);

            if ($row['category'] === 'safety') {
                $billable = $violation;
            }
        }

        // One fine actually billed, so the AR side of module 31 is visible too: a VAT-exempt
        // `violation_fine` line on a normal invoice, booking to misc_income.
        if ($billable !== null) {
            try {
                app(BillViolationFineService::class)->bill($billable);
            } catch (\Throwable $e) {
                // A demo tenant with no active lease in this property cannot be billed, and that is
                // not a reason to fail the seed — the four records are the point.
                $this->command->warn('   (violation fine not billed: '.$e->getMessage().')');
            }
        }
    }

    private function seedAnnouncements(Asset $asset): void
    {
        $marketingLead = User::where('email', 'marketing@mall.test')->first();
        $sender = app(SendAnnouncementAction::class);

        $send = function (array $attrs, float $readShare = 0.0) use ($asset, $marketingLead, $sender): Announcement {
            $announcement = Announcement::create(array_merge([
                'asset_id' => $asset->id,
                'created_by' => $marketingLead?->id,
            ], $attrs));

            $sender->handle($announcement);

            if ($readShare > 0) {
                // The first N recipients opened it. Spread over the hours after the send so the
                // receipts read like people rather than like a fixture.
                $recipients = $announcement->recipients()->orderBy('id')->get();
                $opened = (int) ceil($recipients->count() * $readShare);

                $recipients->take($opened)->each(fn ($recipient, $i) => $recipient->forceFill([
                    'read_at' => $announcement->sent_at?->copy()->addHours($i + 1),
                ])->save());
            }

            return $announcement;
        };

        $send([
            'title' => 'Loading bay closed this Friday',
            'title_ar' => 'إغلاق منطقة التحميل يوم الجمعة',
            'body' => 'The service corridor and loading bay are closed all day Friday for lift maintenance. Please schedule deliveries for Thursday or Saturday.',
            'body_ar' => 'ممر الخدمة ومنطقة التحميل مغلقان طوال يوم الجمعة لأعمال صيانة المصاعد. يُرجى جدولة التوريدات يوم الخميس أو السبت.',
            'category' => Announcement::CATEGORY_OPERATIONS,
            'expires_at' => now()->addDays(6),
        ], readShare: 0.6);

        $send([
            'title' => 'Fire drill — Thursday 3pm',
            'title_ar' => 'تجربة إخلاء — الخميس ٣ مساءً',
            'body' => 'A full evacuation drill runs at 3pm on Thursday. Staff should follow the marshals to the assembly point in the north car park.',
            'body_ar' => 'تجرى تجربة إخلاء كاملة الساعة الثالثة مساء الخميس. على الموظفين اتباع المنظمين إلى نقطة التجمع في الموقف الشمالي.',
            'category' => Announcement::CATEGORY_EMERGENCY,
            'is_pinned' => true,
        ], readShare: 0.35);

        $send([
            'title' => 'Summer trading hours start Monday',
            'title_ar' => 'مواعيد العمل الصيفية تبدأ الاثنين',
            'body' => 'From Monday the mall opens 10:00–01:00 daily. Please update your own signage and staffing rotas.',
            'body_ar' => 'اعتبارًا من الاثنين يفتح المول يوميًا من ١٠:٠٠ إلى ٠١:٠٠. يُرجى تحديث لافتاتكم وجداول العمل.',
            'category' => Announcement::CATEGORY_HOURS,
        ], readShare: 1.0);

        // Written a fortnight early — the case that could not exist while composing WAS sending.
        Announcement::create([
            'asset_id' => $asset->id,
            'created_by' => $marketingLead?->id,
            'title' => 'Eid decorations go up on the 20th',
            'title_ar' => 'تركيب زينة العيد يوم ٢٠',
            'body' => 'Contractors will be working in the atrium overnight from the 20th. Shopfronts stay accessible throughout.',
            'body_ar' => 'سيعمل المقاولون في البهو ليلًا اعتبارًا من يوم ٢٠. تبقى واجهات المحال متاحة طوال الوقت.',
            'category' => Announcement::CATEGORY_EVENT,
            'status' => Announcement::STATUS_SCHEDULED,
            'publish_at' => now()->addDays(9),
        ]);

        // Half-written. Nobody is notified; it sits in the Draft tab until someone finishes it.
        Announcement::create([
            'asset_id' => $asset->id,
            'created_by' => $marketingLead?->id,
            'title' => 'Car park resurfacing — dates TBC',
            'title_ar' => 'إعادة رصف الموقف — المواعيد لم تُحدد',
            'body' => 'Draft: awaiting the contractor\'s programme before this goes out.',
            'body_ar' => 'مسودة: بانتظار برنامج المقاول قبل الإرسال.',
            'category' => Announcement::CATEGORY_OPERATIONS,
            'status' => Announcement::STATUS_DRAFT,
        ]);
    }

    /**
     * HR / Employees (module 24): a small operator payroll for Atriom Walk —
     * staff across departments, two advances (one part-repaid, one part-repaid),
     * and two monthly payroll runs (last month approved + GL-postable, this month
     * still draft). Advances/repayments/approval go through their single-action
     * services so the invariants (active-only grant, no over-repay, positive net)
     * hold exactly as they do in the UI. Returns the created employees so the
     * treasury/custody seeder can grant custody to real holders.
     */
    private function seedHrEmployees(Asset $asset): Collection
    {
        $depts = Department::pluck('id', 'slug'); // slug => id

        $roster = [
            ['name' => 'Yasser Kamal',   'position' => 'Operations Manager',    'dept' => 'operations', 'salary' => 18000, 'pay' => 'bank'],
            ['name' => 'Nourhan Adel',   'position' => 'Facilities Supervisor', 'dept' => 'operations', 'salary' => 11000, 'pay' => 'bank'],
            ['name' => 'Mahmoud Fathy',  'position' => 'Maintenance Technician', 'dept' => 'operations', 'salary' => 6500,  'pay' => 'cash'],
            ['name' => 'Sara Ibrahim',   'position' => 'Cleaning Team Lead',     'dept' => 'operations', 'salary' => 5200,  'pay' => 'cash'],
            ['name' => 'Omar Sherif',    'position' => 'Security Shift Lead',    'dept' => 'operations', 'salary' => 5800,  'pay' => 'cash'],
            ['name' => 'Dina Mostafa',   'position' => 'Accountant',            'dept' => 'accounting', 'salary' => 12000, 'pay' => 'bank'],
            ['name' => 'Hana Youssef',   'position' => 'Leasing Coordinator',   'dept' => 'leasing',    'salary' => 9500,  'pay' => 'bank'],
            ['name' => 'Karim Nabil',    'position' => 'Marketing Executive',   'dept' => 'marketing',  'salary' => 8800,  'pay' => 'bank'],
            ['name' => 'Mona Saad',      'position' => 'HR Officer',            'dept' => 'hr',         'salary' => 9000,  'pay' => 'bank'],
        ];

        $employees = collect();
        foreach ($roster as $i => $r) {
            $employees->push(Employee::create([
                'asset_id' => $asset->id,
                'department_id' => $depts[$r['dept']] ?? null,
                'code' => sprintf('EMP-%03d', $i + 1),
                'name' => $r['name'],
                'national_id' => '2'.rand(9000000000000, 9999999999999),
                'position' => $r['position'],
                'hire_date' => Carbon::now()->subMonths(rand(6, 36))->startOfMonth(),
                'base_salary' => $r['salary'],
                'payment_method' => $r['pay'],
                'phone' => '+201'.rand(100000000, 999999999),
                // The language this employee's payslip is written in. Alternating, so the payroll
                // screen demonstrates both without anyone having to set one by hand.
                'locale' => $i % 2 === 0 ? 'ar' : null,
                'status' => 'active',
            ]));
        }

        // Advances (via services — active-only + no over-repay guards apply).
        $grant = app(GrantEmployeeAdvanceService::class);
        $repay = app(RecordAdvanceRepaymentService::class);

        // Short salary advance — half repaid.
        $adv1 = $grant->grant($employees[2], [
            'amount' => 6000,
            'advance_date' => Carbon::now()->subMonths(2)->toDateString(),
            'type' => 'advance',
            'paid_from' => 'cash',
            'notes' => 'Salary advance.',
        ]);
        $repay->record($adv1, ['amount' => 3000, 'repaid_on' => Carbon::now()->subMonth()->toDateString(), 'method' => 'cash']);

        // Staff loan — two instalments repaid, balance still outstanding.
        $adv2 = $grant->grant($employees[1], [
            'amount' => 20000,
            'advance_date' => Carbon::now()->subMonths(3)->toDateString(),
            'type' => 'loan',
            'paid_from' => 'bank',
            'notes' => 'Staff furniture loan (6-month instalments).',
        ]);
        $repay->record($adv2, ['amount' => 5000, 'repaid_on' => Carbon::now()->subMonths(2)->toDateString(), 'method' => 'bank']);
        $repay->record($adv2, ['amount' => 5000, 'repaid_on' => Carbon::now()->subMonth()->toDateString(), 'method' => 'bank']);

        // Payroll runs: last month approved (GL-postable), current month draft.
        $payrollService = app(PayrollService::class);
        foreach ([1 => 'approved', 0 => 'draft'] as $monthsBack => $finalState) {
            $month = Carbon::now()->subMonths($monthsBack)->startOfMonth();
            $payroll = Payroll::create([
                'number' => Payroll::generateNumber($asset->code, $month),
                'asset_id' => $asset->id,
                'period_month' => $month,
                'description' => 'Monthly payroll — '.$month->format('F Y'),
                'paid_from' => 'bank',
                'bank_account_id' => $this->demoBankAccountForPurpose(BankAccount::PURPOSE_PAYROLL, $asset->id),
                'status' => 'draft',
                'gross_salaries' => 0,
                'salary_tax' => 0,
                'social_insurance' => 0,
                'net_paid' => 0,
            ]);

            // One line per employee; the run header derives from Σ lines on save.
            foreach ($employees as $emp) {
                $gross = (float) $emp->base_salary;
                PayrollLine::create([
                    'payroll_id' => $payroll->id,
                    'employee_id' => $emp->id,
                    'gross' => $gross,
                    'salary_tax' => round($gross * 0.10, 2),
                    'social_insurance' => round($gross * 0.11, 2),
                ]);
            }

            if ($finalState === 'approved') {
                $payrollService->approve($payroll->refresh());
            }
        }

        $this->command->info("   Seeded {$employees->count()} employees, 2 advances (+repayments), 2 payroll runs (1 approved)");

        return $employees;
    }

    /**
     * Treasury / Custody (module 25): grant petty-cash custody to two holders via
     * GrantCustodyService, then settle through SettleCustodyService — one custody
     * fully settled (two expenses + a returned balance), one left partially
     * outstanding. Both services enforce the balance invariant (no over-spend).
     */
    private function seedTreasuryCustody(Collection $employees): void
    {
        if ($employees->count() < 2) {
            return;
        }

        $grant = app(GrantCustodyService::class);
        $settle = app(SettleCustodyService::class);

        // Custody #1 — operations manager, fully settled.
        $c1 = $grant->grant($employees[0], [
            'amount' => 8000,
            'custody_date' => Carbon::now()->subDays(21)->toDateString(),
            'paid_from' => 'cash',
            'reference' => 'Petty cash — site supplies',
            'purpose' => 'Common-area consumables and minor repairs.',
        ]);
        $settle->settle($c1, ['type' => 'expense', 'amount' => 3200, 'transaction_date' => Carbon::now()->subDays(16)->toDateString(), 'category' => 'maintenance', 'notes' => 'Plumbing fittings + sealant.']);
        $settle->settle($c1, ['type' => 'expense', 'amount' => 4100, 'transaction_date' => Carbon::now()->subDays(9)->toDateString(), 'category' => 'cleaning_security', 'notes' => 'Cleaning materials restock.']);
        $settle->settle($c1, ['type' => 'return', 'amount' => 700, 'transaction_date' => Carbon::now()->subDays(5)->toDateString(), 'method' => 'cash', 'notes' => 'Unspent balance returned.']);

        // Custody #2 — facilities supervisor, partially outstanding.
        $c2 = $grant->grant($employees[1], [
            'amount' => 5000,
            'custody_date' => Carbon::now()->subDays(10)->toDateString(),
            'paid_from' => 'bank',
            'reference' => 'Custody — HVAC spares',
            'purpose' => 'Urgent HVAC spare parts.',
        ]);
        $settle->settle($c2, ['type' => 'expense', 'amount' => 2300, 'transaction_date' => Carbon::now()->subDays(4)->toDateString(), 'category' => 'maintenance', 'notes' => 'Compressor capacitor + belts.']);

        $this->command->info('   Seeded 2 custodies (1 fully settled, 1 outstanding)');
    }

    /**
     * Inventory (module 22): two warehouses for the asset, a shared catalog of
     * spare parts + consumables, opening receipts (via StockMovementService so
     * the GL posts the purchase value), a couple of consumptions issued against
     * maintenance, and one shrinkage adjustment. On-hand stays comfortably above
     * reorder levels so no urgent restock noise on first login.
     */
    private function seedInventory(Asset $asset): void
    {
        $svc = app(StockMovementService::class);

        $parts = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Parts Store', 'code' => 'PST', 'category' => 'spare_parts', 'is_active' => true]);
        $consum = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Consumables Store', 'code' => 'CSM', 'category' => 'consumables', 'is_active' => true]);

        $catalog = [
            ['sku' => 'FLT-HVAC-STD', 'name' => 'HVAC air filter (standard)',   'category' => 'HVAC',        'unit' => 'each',  'cost' => 180, 'reorder' => 20,  'wh' => $parts,  'qty' => 60],
            ['sku' => 'BELT-HVAC-A',  'name' => 'HVAC drive belt A-series',      'category' => 'HVAC',        'unit' => 'each',  'cost' => 95,  'reorder' => 10,  'wh' => $parts,  'qty' => 30],
            ['sku' => 'LMP-LED-18W',  'name' => 'LED tube 18W',                  'category' => 'electrical',  'unit' => 'each',  'cost' => 65,  'reorder' => 40,  'wh' => $parts,  'qty' => 200],
            ['sku' => 'CB-16A',       'name' => 'Circuit breaker 16A',           'category' => 'electrical',  'unit' => 'each',  'cost' => 140, 'reorder' => 15,  'wh' => $parts,  'qty' => 45],
            ['sku' => 'PMP-SEAL-32',  'name' => 'Water pump seal 32mm',          'category' => 'plumbing',    'unit' => 'each',  'cost' => 220, 'reorder' => 8,   'wh' => $parts,  'qty' => 24],
            ['sku' => 'TAP-MIX-CHR',  'name' => 'Mixer tap (chrome)',            'category' => 'plumbing',    'unit' => 'each',  'cost' => 480, 'reorder' => 6,   'wh' => $parts,  'qty' => 18],
            ['sku' => 'EXT-CO2-5KG',  'name' => 'CO2 fire extinguisher 5kg',     'category' => 'fire-safety', 'unit' => 'each',  'cost' => 950, 'reorder' => 5,   'wh' => $parts,  'qty' => 15],
            ['sku' => 'CLN-FLOOR-5L', 'name' => 'Floor cleaner concentrate 5L',  'category' => 'cleaning',    'unit' => 'litre', 'cost' => 240, 'reorder' => 30,  'wh' => $consum, 'qty' => 120],
            ['sku' => 'CLN-GLASS-5L', 'name' => 'Glass cleaner 5L',              'category' => 'cleaning',    'unit' => 'litre', 'cost' => 190, 'reorder' => 20,  'wh' => $consum, 'qty' => 80],
            ['sku' => 'BAG-TRASH-XL', 'name' => 'Heavy-duty trash bags (roll)',  'category' => 'cleaning',    'unit' => 'roll',  'cost' => 55,  'reorder' => 50,  'wh' => $consum, 'qty' => 400],
            ['sku' => 'GLOVE-NITR-M', 'name' => 'Nitrile gloves (box of 100)',   'category' => 'cleaning',    'unit' => 'box',   'cost' => 120, 'reorder' => 25,  'wh' => $consum, 'qty' => 150],
            ['sku' => 'PPR-TWL-ROLL', 'name' => 'Paper towel roll',              'category' => 'consumables', 'unit' => 'roll',  'cost' => 35,  'reorder' => 100, 'wh' => $consum, 'qty' => 600],
        ];

        $receiptDate = Carbon::now()->subDays(45);
        foreach ($catalog as $c) {
            $item = InventoryItem::create([
                'sku' => $c['sku'],
                'name' => $c['name'],
                'category' => $c['category'],
                'unit' => $c['unit'],
                'unit_cost' => $c['cost'],
                'reorder_level' => $c['reorder'],
                'is_active' => true,
            ]);

            $svc->receive($c['wh'], $item, $c['qty'], $c['cost'], [
                'moved_on' => $receiptDate->copy()->addDays(rand(0, 10)),
                'reference' => 'PO-2026-'.str_pad((string) rand(1, 90), 4, '0', STR_PAD_LEFT),
            ]);
        }

        // A couple of consumptions issued against maintenance + one shrinkage.
        $filter = InventoryItem::where('sku', 'FLT-HVAC-STD')->first();
        $cleaner = InventoryItem::where('sku', 'CLN-FLOOR-5L')->first();
        $svc->record(['warehouse_id' => $parts->id, 'inventory_item_id' => $filter->id, 'type' => 'consumption', 'quantity' => 12, 'moved_on' => Carbon::now()->subDays(20), 'reference' => 'WO issue', 'notes' => 'Quarterly HVAC filter change.']);
        $svc->record(['warehouse_id' => $consum->id, 'inventory_item_id' => $cleaner->id, 'type' => 'consumption', 'quantity' => 25, 'moved_on' => Carbon::now()->subDays(12), 'notes' => 'Monthly cleaning issue.']);
        $svc->adjust($parts, $filter, -2, ['moved_on' => Carbon::now()->subDays(3), 'notes' => 'Stock-count correction (damaged units).']);

        $this->command->info('   Seeded 2 warehouses, '.count($catalog).' inventory items with stock movements');

        $this->seedProcurement($asset, $parts);
    }

    /**
     * Procurement (module 29): a couple of purchase requests taken through the real workflow so the
     * PO document, the approval ladder and GRNI are all visible on a fresh demo rather than empty.
     * Requested by operations, approved by the manager (self-approval is refused), then one ordered
     * (has a PO to download) and one received (stock landed + GRNI posted).
     */
    private function seedProcurement(Asset $asset, Warehouse $parts): void
    {
        $svc = app(PurchaseRequestService::class);
        $buyer = User::where('email', 'operations@mall.test')->first();     // operations — raises
        $approver = User::where('email', 'manager@mall.test')->first();      // manager — signs off
        $vendor = Vendor::where('name', 'Cool-Air HVAC Services')->first() ?? Vendor::first();
        $filter = InventoryItem::where('sku', 'FLT-HVAC-STD')->first();
        $belt = InventoryItem::where('sku', 'BELT-HVAC-A')->first();

        if (! $buyer || ! $approver || ! $filter) {
            return;
        }

        // 1) Ordered — a PO is out with the supplier, goods not yet in.
        $ordered = $svc->request([
            'asset_id' => $asset->id,
            'justification' => 'Restock HVAC filters ahead of the summer service round.',
            'warehouse_id' => $parts->id,
            'lines' => [['inventory_item_id' => $filter->id, 'quantity' => 40, 'unit_cost' => 180]],
        ], $buyer);
        $svc->approve($ordered, 'Approved — within the maintenance budget.', $approver);
        $svc->order($ordered->fresh(), $vendor?->id, 'CA-Q-2044', $approver);

        // 2) Received — the full loop, so GRNI and a stocked receipt exist on the demo books.
        $received = $svc->request([
            'asset_id' => $asset->id,
            'justification' => 'Drive belts for the rooftop AHUs.',
            'warehouse_id' => $parts->id,
            'lines' => [['inventory_item_id' => $belt?->id ?? $filter->id, 'quantity' => 15, 'unit_cost' => 95]],
        ], $buyer);
        $svc->approve($received, null, $approver);
        $svc->order($received->fresh(), $vendor?->id, 'CA-Q-2051', $approver);
        $svc->receive($received->fresh(), $approver);

        $this->command->info('   Seeded 2 purchase requests (1 ordered w/ PO, 1 received)');
    }

    /**
     * Fixed Assets (module 23): a small capital register for the property with
     * back-dated acquisitions, straight-line monthly depreciation backfilled from
     * each asset's acquisition month to the current month (via DepreciationService
     * — idempotent, one entry per asset+month), and one terminal disposal.
     */
    private function seedFixedAssets(Asset $asset): void
    {
        $register = [
            ['name' => 'Central HVAC chiller unit',      'tag' => 'FA-HVAC-01', 'category' => 'HVAC',      'cost' => 480000, 'salvage' => 30000, 'life' => 120, 'funded' => 'bank', 'age' => 30],
            ['name' => 'Backup diesel generator 250kVA', 'tag' => 'FA-GEN-01',  'category' => 'generator', 'cost' => 620000, 'salvage' => 40000, 'life' => 180, 'funded' => 'bank', 'age' => 24],
            ['name' => 'Passenger elevator (Zone C)',    'tag' => 'FA-ELV-01',  'category' => 'elevator',  'cost' => 850000, 'salvage' => 50000, 'life' => 240, 'funded' => 'bank', 'age' => 20],
            ['name' => 'Management office furniture set', 'tag' => 'FA-FRN-01',  'category' => 'furniture', 'cost' => 90000,  'salvage' => 5000,  'life' => 60,  'funded' => 'cash', 'age' => 18],
            ['name' => 'CCTV + access-control system',    'tag' => 'FA-SEC-01',  'category' => 'IT',        'cost' => 210000, 'salvage' => 10000, 'life' => 72,  'funded' => 'cash', 'age' => 15],
            ['name' => 'Floor scrubber machine',          'tag' => 'FA-CLN-01',  'category' => 'equipment', 'cost' => 75000,  'salvage' => 5000,  'life' => 84,  'funded' => 'cash', 'age' => 28],
        ];

        $earliest = Carbon::now()->startOfMonth();
        foreach ($register as $r) {
            $acq = Carbon::now()->subMonths($r['age'])->startOfMonth();
            if ($acq->lt($earliest)) {
                $earliest = $acq->copy();
            }
            FixedAsset::create([
                'asset_id' => $asset->id,
                'name' => $r['name'],
                'tag' => $r['tag'],
                'category' => $r['category'],
                'acquisition_date' => $acq,
                'acquisition_cost' => $r['cost'],
                'salvage_value' => $r['salvage'],
                'useful_life_months' => $r['life'],
                'method' => 'straight_line',
                'funded_from' => $r['funded'],
                'status' => 'active',
            ]);
        }

        // Backfill monthly depreciation from the earliest acquisition to this month.
        $depr = app(DepreciationService::class);
        $posted = 0;
        for ($m = $earliest->copy(); $m->lte(Carbon::now()->startOfMonth()); $m->addMonth()) {
            $posted += $depr->run($m->copy(), [$asset->id]);
        }

        // Dispose the floor scrubber (replaced; sold for salvage) — terminal.
        $scrubber = FixedAsset::where('tag', 'FA-CLN-01')->first();
        if ($scrubber && $scrubber->status === 'active') {
            app(DisposeFixedAssetService::class)->dispose($scrubber, [
                'disposed_on' => Carbon::now()->subMonth()->toDateString(),
                'proceeds' => 12000,
                'proceeds_account' => 'bank',
                'notes' => 'Replaced; sold to contractor for salvage.',
            ]);
        }

        $this->command->info('   Seeded '.count($register)." fixed assets ({$posted} depreciation entries, 1 disposal)");
    }

    /**
     * Preventive Maintenance (module 26): recurring plans (HVAC, fire-safety,
     * elevator, generator) with past next-due dates so the generator raises work
     * orders immediately, then one work order walked all the way to done with its
     * checklist ticked — showing the full plan → work order → completion flow.
     */
    /**
     * The demo cost base: EGP/hour per trade, and the ceiling above which a contractor must come
     * back for approval.
     *
     * **Seeded here and NOT at install, deliberately.** These are the operator's own numbers: a
     * plausible-looking default hourly rate silently misprices every hour anybody books against
     * it, and a default ceiling silently authorises spend nobody agreed to. A fresh install ships
     * both null and the trade screen asks for them — which is also what ServiceChannel does, where
     * NTE is opt-in per category rather than one global figure.
     *
     * Runs BEFORE the preventive generator, because a plan's estimated hours are priced at the
     * moment the job is raised: with the rates still null, every generated work order would carry
     * a zero estimate and the est-vs-act column would be empty for the life of the demo.
     */
    /**
     * A HISTORY of preventive visits, so the compliance metric has something to measure.
     *
     * PM compliance — what share of planned visits happened by the date they were due — is the
     * headline number every FM system leads with, and it is meaningless against a single cycle.
     * Seeded fresh, every plan had exactly one generated job, all of them still open and all past
     * due, so every plan read **0%**: arithmetically correct and useless, and on screen it reads as
     * a broken system rather than a new one.
     *
     * These rows are written directly rather than through the generator, because the generator
     * raises only what is due TODAY — six months of scans cannot be replayed after the fact. The
     * shape is the generator's (see GeneratePreventiveWorkOrdersService::raiseOrder), including
     * pricing the plan's hours at the trade rate, so a history row and a raised one agree about
     * what a visit is. They carry no checklist items: the closed-out detail of a visit from four
     * months ago is not something the demo needs, and inventing per-item results would be fiction
     * dressed as evidence.
     *
     * The late ones are deliberate. A programme that is 100% compliant everywhere teaches nobody
     * what the number is for, and the operator's real question — which plan is slipping — needs a
     * plan that is slipping.
     */
    private function seedPmHistory(): void
    {
        // occurrences to write, and which of them (counting back from the most recent) ran late
        $history = [
            'HVAC filter & coil service' => ['count' => 8, 'late' => [2]],
            'Monthly fire-safety inspection' => ['count' => 6, 'late' => []],
            'Elevator quarterly maintenance' => ['count' => 4, 'late' => [1]],
            'Generator monthly test-run' => ['count' => 6, 'late' => [3]],
            'Chiller annual overhaul' => ['count' => 2, 'late' => []],
        ];

        $engineer = User::where('email', 'operations@mall.test')->value('id');
        $written = 0;

        foreach (ServicePlan::all() as $plan) {
            $spec = $history[$plan->title] ?? null;

            if ($spec === null) {
                continue;
            }

            $rate = (float) (FacilityWorkOrderLabour::rateFor($plan->trade_id) ?? 0);

            for ($i = 1; $i <= $spec['count']; $i++) {
                // Step back one full plan interval per occurrence, from the cycle before the one
                // that is currently open — so history never collides with the live job.
                $due = $this->stepBack(Carbon::parse($plan->next_due_date), $plan, $i + 1);

                // Late visits landed a few days after they were due; the rest on the day.
                $late = in_array($i, $spec['late'], true);
                $completed = $late ? $due->copy()->addDays(6) : $due->copy();

                $order = $plan->workOrders()->create([
                    'asset_id' => $plan->asset_id,
                    'unit_id' => $plan->unit_id,
                    'area_id' => $plan->area_id,
                    'equipment_id' => $plan->equipment_id,
                    'title' => $plan->title,
                    'trade_id' => $plan->trade_id,
                    'work_order_type' => FacilityWorkOrder::TYPE_PPM,
                    'status' => 'done',
                    'priority' => 'medium',
                    'scheduled_for' => $due->toDateString(),
                    'est_labour_hours' => $plan->est_labour_hours,
                    'est_labour_cost' => $plan->est_labour_hours === null
                        ? null
                        : round((float) $plan->est_labour_hours * $rate, 2),
                    'est_material_cost' => $plan->est_material_cost,
                    'est_service_cost' => $plan->est_service_cost,
                    'department_id' => $plan->department_id,
                    'vendor_id' => $plan->vendor_id,
                    'notes' => (string) $plan->description,
                ]);

                // `completed_at` is what the compliance state reads; set after creation so the
                // model's own hooks see a normal open→done shape.
                $order->forceFill([
                    'completed_at' => $completed->copy()->setTime(15, 0),
                    'assigned_to_user_id' => $engineer,
                ])->save();

                // The hours actually booked — a little over on the late ones, which is usually why
                // they were late. This is what gives the register a real est-vs-act spread.
                if ($plan->est_labour_hours !== null && $engineer !== null) {
                    FacilityWorkOrderLabour::create([
                        'facility_work_order_id' => $order->id,
                        'user_id' => $engineer,
                        'worked_on' => $completed->toDateString(),
                        'hours' => round((float) $plan->est_labour_hours * ($late ? 1.25 : 1.0), 2),
                        'recorded_by_user_id' => $engineer,
                    ]);
                }

                $written++;
            }
        }

        $this->command->info("   Seeded {$written} past preventive visits (so compliance has a history to measure)");
    }

    /** One plan interval back, `$times` times — the same units a plan schedules in. */
    private function stepBack(Carbon $from, ServicePlan $plan, int $times): Carbon
    {
        $step = (int) $plan->frequency_value * $times;

        return match ($plan->frequency_unit) {
            'days' => $from->copy()->subDays($step),
            'weeks' => $from->copy()->subWeeks($step),
            'years' => $from->copy()->subYears($step),
            default => $from->copy()->subMonths($step),
        };
    }

    private function seedTradeCostBase(): void
    {
        // Only the trades this mall actually books against; the rest stay null, which is the
        // honest state for a trade nobody has priced yet.
        $rates = [
            'elevator' => ['rate' => 250, 'nte' => 15000],
            'hvac' => ['rate' => 200, 'nte' => 10000],
            'electrical' => ['rate' => 180, 'nte' => 8000],
            'plumbing' => ['rate' => 160, 'nte' => 6000],
            'fire-safety' => ['rate' => 220, 'nte' => 12000],
            'generator' => ['rate' => 240, 'nte' => 12000],
            'cleaning' => ['rate' => 90, 'nte' => 3000],
        ];

        foreach ($rates as $code => $v) {
            Trade::where('code', $code)->update([
                'standard_hourly_rate' => $v['rate'],
                'default_nte' => $v['nte'],
            ]);
        }
    }

    private function seedPreventiveMaintenance(Asset $asset): void
    {
        $this->seedTradeCostBase();

        $ops = Department::where('slug', 'operations')->value('id');
        $coolAir = Vendor::where('email', 'ops@cool-air.eg')->value('id');
        $fireSafe = Vendor::where('email', 'audit@firesafe.eg')->value('id');
        $brightSpark = Vendor::where('email', 'service@brightspark.eg')->value('id');

        $this->seedEquipment($asset);

        // Per-property SLA (FR-CM-05): this mall has a 24/7 engineering team, so it runs a
        // tighter clock than the operator default (urgent 4h, high 24h). Only the
        // priorities that actually differ are recorded — the rest fall back, which is the
        // point of a policy row being an override rather than a requirement.
        foreach (['urgent' => 2, 'high' => 12] as $priority => $hours) {
            SlaPolicy::updateOrCreate(
                ['asset_id' => $asset->id, 'priority' => $priority],
                ['resolve_hours' => $hours],
            );
        }

        // `equip` = the machine this plan services (FR-PPM-01/03). A plan naming one is
        // `fixed` (per-asset) maintenance; the rest stay `routine` and property-wide.
        $plans = [
            ['title' => 'HVAC filter & coil service',      'category' => 'hvac',        'unit' => 'weeks',  'freq' => 2, 'due' => -6, 'hours' => 3,  'dept' => $ops, 'vendor' => $coolAir,     'equip' => 'AHU-01',
                'checklist' => ['Inspect filter condition', 'Replace filter cartridge', 'Clean condenser coil', 'Check airflow pressure']],
            ['title' => 'Monthly fire-safety inspection',  'category' => 'fire-safety', 'unit' => 'months', 'freq' => 1, 'due' => -3, 'hours' => 4,  'dept' => $ops, 'vendor' => $fireSafe,    'equip' => null,
                'checklist' => ['Inspect fire extinguishers', 'Test fire-alarm panel', 'Check emergency exits', 'Verify signage & lighting']],
            ['title' => 'Elevator quarterly maintenance',  'category' => 'elevator',    'unit' => 'months', 'freq' => 3, 'due' => -10, 'hours' => 7, 'dept' => $ops, 'vendor' => null,         'equip' => 'LFT-01',
                'checklist' => ['Inspect cables & pulleys', 'Test brakes & governor', 'Check door mechanisms', 'Load test', 'Certify safety']],
            ['title' => 'Generator monthly test-run',      'category' => 'generator',   'unit' => 'months', 'freq' => 1, 'due' => -2, 'hours' => 2,  'dept' => $ops, 'vendor' => $brightSpark, 'equip' => 'GEN-01',
                'checklist' => ['Check fuel & oil levels', 'Run under load 15 min', 'Inspect battery', 'Log readings']],
            // Yearly (FR-PPM-02) — the unit that used to fire monthly.
            ['title' => 'Chiller annual overhaul',         'category' => 'hvac',        'unit' => 'years',  'freq' => 1, 'due' => -1, 'hours' => 16,  'dept' => $ops, 'vendor' => $coolAir,     'equip' => 'CH-01',
                'checklist' => ['Strip & inspect compressor', 'Replace refrigerant', 'Pressure-test circuit', 'Recommission']],
        ];

        $equipmentIds = Equipment::where('asset_id', $asset->id)->pluck('id', 'code');
        // The trade register replaced the translation-backed `category` string (2026-08-20).
        $trades = Trade::pluck('id', 'code');

        foreach ($plans as $p) {
            $equipmentId = $p['equip'] ? ($equipmentIds[$p['equip']] ?? null) : null;

            ServicePlan::create([
                'asset_id' => $asset->id,
                'unit_id' => null,
                'equipment_id' => $equipmentId,
                'title' => $p['title'],
                'trade_id' => $trades[$p['category']] ?? null,
                'plan_type' => $equipmentId
                    ? ServicePlan::MAINTENANCE_TYPE_FIXED
                    : ServicePlan::MAINTENANCE_TYPE_ROUTINE,
                'description' => $p['title'].' — preventive schedule.',
                'frequency_unit' => $p['unit'],
                'frequency_value' => $p['freq'],
                'checklist' => $p['checklist'],
                // What the visit is expected to take. Priced at GENERATION against the trade's
                // rate, so a scheduled job arrives with an estimate to answer its actuals — the
                // est-vs-act column is empty and meaningless without it.
                'est_labour_hours' => $p['hours'],
                'department_id' => $p['dept'],
                'vendor_id' => $p['vendor'],
                'next_due_date' => Carbon::now()->addDays($p['due'])->toDateString(),
                'is_active' => true,
            ]);
        }

        // Raise work orders for every due plan (idempotent).
        $created = app(GeneratePreventiveWorkOrdersService::class)->run(Carbon::now()->toDateString());

        // Complete the oldest open work order to show a full lifecycle. Driven through
        // the real service so the demo data can't encode a state the app would refuse.
        $engineer = User::where('email', 'operations@mall.test')->first();
        $wo = FacilityWorkOrder::where('status', 'open')->orderBy('scheduled_for')->first();
        if ($wo && $engineer) {
            $svc = app(FacilityWorkOrderService::class);
            $svc->transition($wo, 'in_progress', $engineer->id);

            // The last item fails — a PPM visit that finds a fault is the normal case,
            // and it's what corrective maintenance gets raised from (FR-CM-01). A fail
            // does not block closure; only an unchecked item does.
            $items = $wo->items()->orderBy('id')->get();
            foreach ($items as $i => $item) {
                $svc->markItem(
                    $item,
                    $items->count() > 1 && $i === $items->count() - 1
                        ? FacilityWorkOrderItem::RESULT_FAIL
                        : FacilityWorkOrderItem::RESULT_PASS,
                    $engineer->id,
                );
            }

            // The failed check raises corrective work (FR-CM-01) — the canonical flow: the
            // visit found a fault, the visit still closes, and the fix becomes its own job.
            $failed = $wo->items()->failed()->first();

            if ($failed) {
                app(RaiseCorrectiveWorkOrderService::class)->fromFailedCheck($failed, [
                    'execution_type' => FacilityWorkOrder::EXECUTION_EXTERNAL,
                    'vendor_id' => $coolAir,
                    'priority' => 'urgent',
                    'description' => 'Found during the scheduled visit: '.$failed->label.' failed inspection and needs corrective work.',
                    'scheduled_for' => Carbon::now()->addDays(2)->toDateString(),
                ]);
            }

            $svc->transition($wo, 'done', $engineer->id);
        }

        // A follow-up on the closed order (FR-CM-14/15) — the external company signed it off
        // but the work wasn't finished. A NEW linked job, not a reopen.
        if ($wo && $engineer) {
            app(RaiseCorrectiveWorkOrderService::class)->asFollowUp($wo->fresh(), [
                'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL,
                'assigned_to_user_id' => $engineer->id,
                'description' => 'Vendor closed the job but the fault recurred within a week — re-inspect and finish the fix.',
                'scheduled_for' => Carbon::now()->addDays(3)->toDateString(),
            ]);
        }

        $this->seedPmHistory();

        $this->command->info('   Seeded '.count($plans)." preventive plans, {$created} work orders generated (1 completed)");
    }

    /**
     * What a job COSTS — the operational close-out, made demonstrable.
     *
     * Everything below already worked; none of it had any demo data, so Operations rendered a
     * register of jobs with 0.00 in every cost column and empty NTE/failure/permit screens. A
     * capability nobody can SEE reads exactly like one that was never built, which is the same
     * failure class as a service with no entry point — so the fixture is part of the feature.
     *
     * Driven through the real services wherever one exists (proposals, parts, permits, vendor
     * bills), so the demo data cannot encode a state the application would refuse.
     *
     * **Trade rates and NTE ceilings are seeded HERE and not at install, deliberately.** They are
     * the operator's own cost base: a plausible-looking default hourly rate silently misprices
     * every hour anybody books against it, and a default ceiling silently authorises spend. A
     * fresh install ships them null and the trade screen asks for them — which is also what
     * ServiceChannel does, where NTE is opt-in per category rather than a global figure.
     */
    private function seedFacilityCosts(Asset $asset): void
    {
        $engineer = User::where('email', 'operations@mall.test')->first();
        $manager = User::where('email', 'manager@mall.test')->first();
        $coolAir = Vendor::where('email', 'ops@cool-air.eg')->first();

        if (! $engineer || ! $manager) {
            return;
        }

        $done = FacilityWorkOrder::where('status', 'done')->orderBy('id')->first();
        $corrective = FacilityWorkOrder::where('reference', 'like', 'CM-%')
            ->where('execution_type', FacilityWorkOrder::EXECUTION_EXTERNAL)
            ->orderBy('id')->first();

        // ── The completed PPM visit: hours, so a finished job reports what it cost ──────────
        //
        // The rate is frozen onto the row at entry (see FacilityWorkOrderLabour::rateFor) — a
        // later rate change must never restate the cost of work already done.
        if ($done) {
            foreach ([['d' => 3, 'h' => 4.0], ['d' => 2, 'h' => 3.0]] as $shift) {
                FacilityWorkOrderLabour::create([
                    'facility_work_order_id' => $done->id,
                    'user_id' => $engineer->id,
                    'worked_on' => Carbon::now()->subDays($shift['d'])->toDateString(),
                    'hours' => $shift['h'],
                    'recorded_by_user_id' => $engineer->id,
                    'notes' => 'Scheduled visit — inspection and adjustment.',
                ]);
            }

            // Problem · cause · remedy — the Maximo failure triad. This is what makes a register of
            // jobs answer "what keeps breaking, and why", rather than only "what did we spend".
            $done->forceFill([
                'failure_problem_id' => FailureCode::where('code', 'noise')->value('id'),
                'failure_cause_id' => FailureCode::where('code', 'wear')->value('id'),
                'failure_remedy_id' => FailureCode::where('code', 'adjusted')->value('id'),
            ])->save();
        }

        // ── The corrective job: a quote, a ceiling, and a supplementary that breaches it ────
        if ($corrective && $coolAir) {
            $corrective->forceFill([
                'nte_amount' => Trade::whereKey($corrective->trade_id)->value('default_nte'),
            ])->save();

            $proposals = app(WorkOrderProposalService::class);

            // The contractor quotes; approval sets the estimate. Under the ceiling, so it passes
            // without argument — the control should be invisible when it is respected.
            $first = $proposals->submit($corrective, [
                'vendor_id' => $coolAir->id,
                'labour_amount' => 6000,
                'material_amount' => 4000,
                'scope' => 'Strip and inspect the traction gear, replace worn sheave bearings, re-tension and re-certify.',
            ], $manager);
            $proposals->approve($first, $manager);

            // Then the job opens up — the classic contractor moment, and the one the ceiling
            // exists for. A supplementary quote does NOT restate the original (that would erase
            // what was agreed); it adds to it, and the total now sits above the NTE, which the
            // job says out loud without blocking anybody. Deciding to spend it is the operator's
            // call, made visibly rather than discovered on the invoice.
            $supp = $proposals->submit($corrective, [
                'vendor_id' => $coolAir->id,
                'is_supplementary' => true,
                'material_amount' => 7000,
                'scope' => 'Additional: governor rope found glazed on strip-down — replace and re-test.',
            ], $manager);
            $proposals->approve($supp, $manager);

            // Parts off the shelf, on the same job as the contractor's labour: requested by the
            // engineer, approved by somebody else (self-approval is refused — the control is a
            // second pair of eyes), which is what moves the stock and lands the cost.
            $parts = app(WorkOrderPartService::class);
            $breaker = InventoryItem::where('sku', 'CB-16A')->first();

            if ($breaker) {
                $draw = $parts->requestInternal($corrective, [
                    'inventory_item_id' => $breaker->id,
                    'warehouse_id' => Warehouse::where('name', 'Parts Store')->value('id') ?? 1,
                    'quantity' => 2,
                ], $engineer->id);
                $parts->approve($draw, $manager);
            }

            $corrective->forceFill([
                'failure_problem_id' => FailureCode::where('code', 'noise')->value('id'),
                'failure_cause_id' => FailureCode::where('code', 'part_failure')->value('id'),
                'failure_remedy_id' => FailureCode::where('code', 'part_replaced')->value('id'),
            ])->save();

            // The contractor's invoice, FILED AGAINST THE JOB. This is the whole point of the
            // work order being a cost object: the bill posts to the ledger exactly as it always
            // did, and the job it belongs to can now say what it actually cost.
            $billDate = Carbon::now()->subDays(4);
            $subtotal = 17000.0;
            $vat = round($subtotal * 0.14, 2);

            $bill = VendorBill::create([
                'vendor_id' => $coolAir->id,
                'asset_id' => $asset->id,
                'facility_work_order_id' => $corrective->id,
                'category' => 'maintenance',
                'bill_date' => $billDate->toDateString(),
                'due_date' => $billDate->copy()->addDays(30)->toDateString(),
                'reference' => 'CA-'.strtoupper(Str::random(6)),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => $subtotal + $vat,
                'status' => 'draft',
            ]);
            app(VendorBillService::class)->approve($bill);

            $corrective->refresh()->recomputeCosts();
        }

        // ── The breach: an invoice above what was authorised, with nobody's approval on it ──
        //
        // This is what the ceiling is FOR, and it is a different event from the supplementary
        // above: approving a supplementary IS the authorisation, so an approved one can never be
        // a breach — it raises the ceiling by its own amount. A breach is the contractor invoicing
        // past the ceiling having asked nobody. Shown, never blocked (see overNteBy) — the work is
        // done and jamming accounts payable would punish the wrong party; the control is that the
        // overspend is visible and attributable instead of surfacing months later in the P&L.
        $scheduled = FacilityWorkOrder::where('reference', 'like', 'WO-%')
            ->where('status', '!=', 'done')
            ->whereNotNull('vendor_id')
            ->orderBy('id')->first();

        if ($scheduled && $coolAir) {
            $scheduled->forceFill([
                'nte_amount' => Trade::whereKey($scheduled->trade_id)->value('default_nte'),
            ])->save();

            $overDate = Carbon::now()->subDays(2);
            $overSubtotal = 12500.0;
            $overVat = round($overSubtotal * 0.14, 2);

            $overBill = VendorBill::create([
                'vendor_id' => $coolAir->id,
                'asset_id' => $asset->id,
                'facility_work_order_id' => $scheduled->id,
                'category' => 'maintenance',
                'bill_date' => $overDate->toDateString(),
                'due_date' => $overDate->copy()->addDays(30)->toDateString(),
                'reference' => 'CA-'.strtoupper(Str::random(6)),
                'subtotal' => $overSubtotal,
                'vat_amount' => $overVat,
                'total' => $overSubtotal + $overVat,
                'status' => 'draft',
            ]);
            app(VendorBillService::class)->approve($overBill);

            $scheduled->refresh()->recomputeCosts();
        }

        $this->seedRepeatVisits($asset, $engineer);

        $this->seedWorkPermits($asset, $corrective, $coolAir, $engineer);

        $costed = FacilityWorkOrder::where('act_total_cost', '>', 0)->count();
        $overNte = FacilityWorkOrder::overNte()->count();
        $this->command->info("   Seeded work-order costs on {$costed} jobs (labour · parts · contractor bills), 2 proposals, {$overNte} over NTE, 3 permits");
    }

    /**
     * Permits to work — the safety control, in the three states that matter.
     *
     * The register is only worth having if the LAPSED one is in it: an issued permit whose window
     * has passed and which nobody closed out means no one recorded that the welding stopped and
     * the area was checked. That is the row a safety audit looks for, so the demo has one.
     */
    /**
     * The fault that keeps coming back (benchmark scenario S6).
     *
     * The cheapest high-value signal in retail FM: three visits to the same shop for the same
     * trade inside a month is one unfixed fault and three invoices, and without the link the
     * register shows three unrelated successes. ServiceChannel surfaces it; so does Maximo.
     *
     * Seeded on a UNIT with no equipment named, deliberately — that exercises the fallback a
     * tenant-reported fault actually takes (a shop is what a tenant reports about), which the
     * plant-room jobs never do.
     *
     * **`created_at` is set explicitly, and that is the point.** Repeat detection orders by it, and
     * everything a seeder writes shares one timestamp — so a repeat pair written the obvious way
     * ends up with neither job before the other and the signal silently reads clean. Real jobs are
     * days apart; the fixture has to be too, or it is not a fixture of the thing.
     */
    private function seedRepeatVisits(Asset $asset, User $engineer): void
    {
        $unitId = Unit::where('asset_id', $asset->id)
            ->where('status', 'occupied')
            ->orderBy('id')
            ->value('id');

        $tradeId = Trade::where('code', 'electrical')->value('id');

        if ($unitId === null || $tradeId === null) {
            return;
        }

        $visits = [
            ['days' => 24, 'note' => 'Lighting circuit in the shopfront tripping intermittently. Breaker reset, tested and left working.'],
            ['days' => 12, 'note' => 'Same circuit tripped again over the weekend. Reset and load-tested — no fault found on site.'],
            ['days' => 3,  'note' => 'Tripped a third time. Tenant reports it happens under full display lighting.'],
        ];

        foreach ($visits as $i => $v) {
            $raisedOn = Carbon::now()->subDays($v['days']);
            $last = $i === count($visits) - 1;

            $order = FacilityWorkOrder::create([
                'asset_id' => $asset->id,
                'unit_id' => $unitId,
                'title' => 'Shopfront lighting circuit tripping',
                'trade_id' => $tradeId,
                'work_order_type' => FacilityWorkOrder::TYPE_CM,
                // A corrective job must say who does it (FR-CM-02) — the model refuses one that
                // does not, which is how this fixture got caught being unrealistic.
                'execution_type' => FacilityWorkOrder::EXECUTION_INTERNAL,
                'status' => $last ? 'open' : 'done',
                'priority' => 'high',
                'scheduled_for' => $raisedOn->toDateString(),
                'assigned_to_user_id' => $engineer->id,
                'department_id' => Department::where('slug', 'operations')->value('id'),
                'description' => $v['note'],
            ]);

            $order->forceFill([
                'created_at' => $raisedOn,
                'completed_at' => $last ? null : $raisedOn->copy()->addHours(3),
                // The first two were closed "no fault found" — which is exactly how a recurring
                // fault survives three visits and reads as three successes.
                'failure_problem_id' => FailureCode::where('code', 'intermittent')->value('id'),
                'failure_remedy_id' => $last ? null : FailureCode::where('code', 'no_fault_found')->value('id'),
            ])->save();

            if (! $last) {
                FacilityWorkOrderLabour::create([
                    'facility_work_order_id' => $order->id,
                    'user_id' => $engineer->id,
                    'worked_on' => $raisedOn->toDateString(),
                    'hours' => 1.5,
                    'recorded_by_user_id' => $engineer->id,
                ]);
            }
        }
    }

    private function seedWorkPermits(Asset $asset, ?FacilityWorkOrder $order, ?Vendor $vendor, User $issuer): void
    {
        $svc = app(WorkPermitService::class);

        // Live now — what a guard on the door checks against.
        $live = WorkPermit::create([
            'asset_id' => $asset->id,
            'type' => WorkPermit::TYPE_HOT_WORK,
            'vendor_id' => $vendor?->id,
            'contractor_name' => $vendor?->name ?? 'Cool Air Services',
            'facility_work_order_id' => $order?->id,
            'location' => 'Lift machine room, roof level',
            'description' => 'Cutting and welding of bracketry for the replacement sheave bearing.',
            'conditions' => 'Fire watch posted throughout and for 60 minutes after. Extinguisher and blanket on site. Detector head isolated and RESTORED at close.',
            'valid_from' => Carbon::now()->subHours(2),
            'valid_to' => Carbon::now()->addHours(3),
        ]);
        $svc->issue($live, $issuer);

        // Issued, window passed, nobody signed it off — the finding the nightly scan reports.
        $lapsed = WorkPermit::create([
            'asset_id' => $asset->id,
            'type' => WorkPermit::TYPE_ELECTRICAL_ISOLATION,
            'contractor_name' => 'BrightSpark Electrical',
            'location' => 'Main switch room, basement',
            'description' => 'Isolation of distribution board DB-3 to change a faulty breaker.',
            'conditions' => 'Lock-off applied and keys held by the duty engineer. Prove dead before work.',
            'valid_from' => Carbon::now()->subDays(2)->setTime(9, 0),
            'valid_to' => Carbon::now()->subDays(2)->setTime(17, 0),
        ]);
        $svc->issue($lapsed, $issuer);

        // Closed out properly — the control working, which the register should also show.
        $closed = WorkPermit::create([
            'asset_id' => $asset->id,
            'type' => WorkPermit::TYPE_WORKING_AT_HEIGHT,
            'contractor_name' => 'CleanFleet Services',
            'location' => 'Atrium, level 2 void',
            'description' => 'Cleaning the atrium glazing from a mobile tower.',
            'conditions' => 'Area barriered below. Tower erected and inspected by a competent person.',
            'valid_from' => Carbon::now()->subDays(6)->setTime(7, 0),
            'valid_to' => Carbon::now()->subDays(6)->setTime(12, 0),
        ]);
        $svc->issue($closed, $issuer);
        $svc->close($closed, 'Tower struck, barriers removed, area inspected and left clear.', $issuer);
    }

    /**
     * The maintainable-asset register (FR-PPM-03/04/05): the machines themselves, with a
     * component sub-code tree, the accounting twin where the fixed-asset register happens
     * to hold one, and the spare parts that fit each.
     */
    private function seedEquipment(Asset $asset): void
    {
        // [code, name_en, name_ar, category, location, fixed-asset tag, sub-codes]
        $machines = [
            ['ESC-01', 'Main escalator (ground → 1st)', 'السلم الكهربائي الرئيسي (أرضي ← أول)', 'elevator', 'Atrium, north side', null, [
                ['ESC-01-MOT', 'Drive motor', 'محرك الإدارة'],
                ['ESC-01-HND', 'Handrail assembly', 'مجموعة الدرابزين'],
                ['ESC-01-STP', 'Step chain', 'سلسلة الدرجات'],
            ]],
            ['LFT-01', 'Passenger lift (Zone C)', 'مصعد الركاب (منطقة ج)', 'elevator', 'Core C', 'FA-ELV-01', [
                ['LFT-01-CAB', 'Cabin & doors', 'الكابينة والأبواب'],
                ['LFT-01-CBL', 'Hoist cables', 'كابلات الرفع'],
            ]],
            ['CH-01', 'Central chiller unit', 'وحدة التبريد المركزية', 'hvac', 'Roof, zone B', 'FA-HVAC-01', [
                ['CH-01-PMP', 'Circulation pump', 'مضخة الدوران'],
                ['CH-01-CMP', 'Compressor', 'الضاغط'],
            ]],
            ['AHU-01', 'Air handling unit — atrium', 'وحدة مناولة الهواء — البهو', 'hvac', 'Plant room 2', null, []],
            ['GEN-01', 'Backup diesel generator 250kVA', 'مولد ديزل احتياطي ٢٥٠ ك.ف.أ', 'generator', 'Basement, plant room 1', 'FA-GEN-01', [
                ['GEN-01-BAT', 'Starter battery bank', 'بنك بطاريات البدء'],
            ]],
            ['FP-01', 'Fire pump', 'مضخة الحريق', 'fire-safety', 'Basement, pump room', null, []],
        ];

        $partIds = InventoryItem::pluck('id', 'sku');
        $fixedAssetIds = FixedAsset::where('asset_id', $asset->id)->pluck('id', 'tag');
        // `$category` in $machines is the trade CODE — the register replaced the string (2026-08-20).
        $trades = Trade::pluck('id', 'code');
        $count = 0;

        foreach ($machines as [$code, $nameEn, $nameAr, $category, $location, $faTag, $subs]) {
            $parent = Equipment::create([
                'asset_id' => $asset->id,
                'code' => $code,
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'trade_id' => $trades[$category] ?? null,
                'location' => $location,
                'fixed_asset_id' => $faTag ? ($fixedAssetIds[$faTag] ?? null) : null,
                'is_active' => true,
            ]);
            $count++;

            foreach ($subs as [$subCode, $subEn, $subAr]) {
                Equipment::create([
                    'asset_id' => $asset->id,
                    'parent_id' => $parent->id,
                    'code' => $subCode,
                    'name_en' => $subEn,
                    'name_ar' => $subAr,
                    'trade_id' => $trades[$category] ?? null,
                    'location' => $location,
                    'is_active' => true,
                ]);
                $count++;
            }

            // Compatible spare parts (FR-PPM-05) — drawn from the shared item catalog.
            $fits = match ($category) {
                'hvac' => ['FLT-HVAC-STD', 'BELT-HVAC-A'],
                'fire-safety' => ['EXT-CO2-5KG'],
                'elevator' => ['LMP-LED-18W'],
                'generator' => ['CB-16A'],
                default => [],
            };
            $ids = collect($fits)->map(fn ($sku) => $partIds[$sku] ?? null)->filter()->values()->all();
            if ($ids !== []) {
                $parent->inventoryItems()->sync($ids);
            }
        }

        $this->command->info("   Seeded {$count} equipment records (sub-codes + compatible parts)");
    }

    /**
     * Accounts Payable (module 21 source): vendor bills across the maintenance /
     * cleaning-security / other categories in every lifecycle state (draft →
     * approved → partially paid → paid). Approvals + payments go through
     * VendorBillService so paid_amount / balance / status stay derived.
     */
    private function seedVendorBills(Asset $asset): void
    {
        $svc = app(VendorBillService::class);
        $vendors = Vendor::whereIn('email', [
            'ops@cool-air.eg', 'service@brightspark.eg', 'help@purewater.eg',
            'contact@cleanfleet.eg', 'ops@secureguard.eg', 'support@peststop.eg',
        ])->get()->keyBy('email');

        $bills = [
            ['email' => 'contact@cleanfleet.eg',  'category' => 'cleaning_security', 'subtotal' => 40000, 'days' => 55, 'state' => 'paid'],
            ['email' => 'ops@secureguard.eg',     'category' => 'cleaning_security', 'subtotal' => 60000, 'days' => 50, 'state' => 'paid'],
            ['email' => 'ops@cool-air.eg',        'category' => 'maintenance',       'subtotal' => 30000, 'days' => 40, 'state' => 'partially_paid'],
            ['email' => 'service@brightspark.eg', 'category' => 'maintenance',       'subtotal' => 15000, 'days' => 30, 'state' => 'approved'],
            ['email' => 'help@purewater.eg',      'category' => 'maintenance',       'subtotal' => 7500,  'days' => 20, 'state' => 'approved'],
            ['email' => 'support@peststop.eg',    'category' => 'other',             'subtotal' => 5000,  'days' => 12, 'state' => 'draft'],
            ['email' => 'contact@cleanfleet.eg',  'category' => 'cleaning_security', 'subtotal' => 40000, 'days' => 8,  'state' => 'draft'],
        ];

        $count = 0;
        foreach ($bills as $b) {
            $vendor = $vendors->get($b['email']);
            if (! $vendor) {
                continue;
            }

            $vat = round($b['subtotal'] * 0.14, 2);
            $total = $b['subtotal'] + $vat;
            $billDate = Carbon::now()->subDays($b['days']);

            $bill = VendorBill::create([
                'vendor_id' => $vendor->id,
                'asset_id' => $asset->id,
                'category' => $b['category'],
                'bill_date' => $billDate->toDateString(),
                'due_date' => $billDate->copy()->addDays(30)->toDateString(),
                'reference' => 'INV-'.strtoupper(Str::random(6)),
                'subtotal' => $b['subtotal'],
                'vat_amount' => $vat,
                'total' => $total,
                'status' => 'draft',
            ]);

            if (in_array($b['state'], ['approved', 'partially_paid', 'paid'], true)) {
                $svc->approve($bill);
            }
            if ($b['state'] === 'partially_paid') {
                $svc->recordPayment($bill, round($total * 0.5, 2), 'bank_transfer', $billDate->copy()->addDays(10));
            }
            if ($b['state'] === 'paid') {
                $svc->recordPayment($bill, (float) $total, 'bank_transfer', $billDate->copy()->addDays(15));
            }

            $count++;
        }

        $this->command->info("   Seeded {$count} vendor bills (draft / approved / partly-paid / paid)");
    }

    /**
     * Direct operating expenses (module 21 source): a spread of recorded expenses
     * across categories and cash/bank funding, some VAT-bearing, dated over the
     * last ~2 months so the P&L and GL both have out-of-pocket cost data.
     */
    private function seedExpenses(Asset $asset): void
    {
        $samples = [
            ['category' => 'utilities',         'desc' => 'Common-area electricity top-up',  'amount' => 8500, 'paid' => 'bank', 'days' => 50, 'vat' => true],
            ['category' => 'utilities',         'desc' => 'Water authority bill',            'amount' => 3200, 'paid' => 'bank', 'days' => 44, 'vat' => false],
            ['category' => 'maintenance',       'desc' => 'Emergency glass-door repair',     'amount' => 2400, 'paid' => 'cash', 'days' => 33, 'vat' => true],
            ['category' => 'admin',             'desc' => 'Office stationery & printing',    'amount' => 1200, 'paid' => 'cash', 'days' => 28, 'vat' => true],
            ['category' => 'marketing',         'desc' => 'Seasonal decoration materials',   'amount' => 6000, 'paid' => 'cash', 'days' => 20, 'vat' => true],
            ['category' => 'cleaning_security', 'desc' => 'Extra weekend security shift',    'amount' => 3500, 'paid' => 'bank', 'days' => 14, 'vat' => false],
            ['category' => 'other',             'desc' => 'Municipality permit renewal',     'amount' => 4500, 'paid' => 'bank', 'days' => 7,  'vat' => false],
        ];

        foreach ($samples as $s) {
            $vat = $s['vat'] ? round($s['amount'] * 0.14, 2) : 0;
            Expense::create([
                'asset_id' => $asset->id,
                'category' => $s['category'],
                'description' => $s['desc'],
                'amount' => $s['amount'],
                'vat_amount' => $vat,
                'total' => $s['amount'] + $vat,
                'paid_from' => $s['paid'],
                'bank_account_id' => $this->demoBankAccountFor($s['paid'], $s['days']),
                'expense_date' => Carbon::now()->subDays($s['days'])->toDateString(),
                'status' => 'recorded',
            ]);
        }

        $this->command->info('   Seeded '.count($samples).' direct expenses');
    }

    /**
     * Two bank accounts in ONE mall — the situation EG-12 exists for — and money that went through
     * each.
     *
     * Nothing seeded a `BankAccount` at all, so on a fresh demo every bank-account picker on the six
     * money forms was EMPTY and the whole feature read as unbuilt. Demo data is part of the feature:
     * a screen with no rows to choose from is indistinguishable from one that was never wired.
     *
     * **Each gets its OWN chart leaf, and neither may be a POSTING ROLE account.** The first cut
     * took "the first two postable asset accounts by code", which on the seeded chart are
     * `11101001 Main Cashier` and `11102001 Bank Account` — the `cash` and `bank` role accounts
     * themselves. That demo shows nothing: CIB's receipts would land in the till, and NBE would
     * resolve to exactly the account `App\Support\MoneyAccount`'s floor already picks, so the
     * separation the whole feature is about would be invisible on the trial balance and the
     * reconciliation matcher would still offer one bank's postings against the other's statement.
     * (The regression test's fixture was already careful about this; the seeder was not.)
     *
     * So the demo does what a mall's accountant does: two new leaves under `11102 Banks`, beside
     * the generic one rather than instead of it. `11102001` stays the `bank` role — the right
     * answer for any document that names no account at all.
     *
     * Runs BEFORE the money is seeded, so the receipts below can name an account as they are
     * created. Stamping them afterwards would mean an UPDATE, and `bank_account_id` is classified
     * REFUSED on a committed expense — quite rightly, since it decides where the cash leg posts.
     */
    private function seedBankAccounts(Asset $asset): void
    {
        $banks = LedgerAccount::query()->where('code', '11102')->first();

        if ($banks === null) {
            return; // A chart we do not recognise — leave it alone rather than guess a parent.
        }

        $leaf = fn (string $code, string $en, string $ar): LedgerAccount => LedgerAccount::updateOrCreate(
            ['code' => $code],
            [
                'parent_id' => $banks->id,
                'name_en' => $en,
                'name_ar' => $ar,
                'type' => 'asset',
                'is_postable' => true,
                'is_active' => true,
            ],
        );

        $this->cibAccount = BankAccount::updateOrCreate(
            ['asset_id' => $asset->id, 'account_number' => '100-2003-004455'],
            [
                'name' => 'CIB — operating account',
                'bank_name' => 'Commercial International Bank',
                'iban' => 'EG380019000500000000263180002',
                'currency' => 'EGP',
                'ledger_account_id' => $leaf('11102002', 'CIB — Operating Account', 'حساب البنك التجاري الدولي — التشغيل')->id,
                'purpose' => BankAccount::PURPOSE_OPERATING,
                // The property's DEFAULT: every money form on Atriom Walk opens with this filled in
                // and the operator confirms rather than chooses, which is the half that makes
                // requiring an account on a bank rail reasonable rather than a chore. Without it the
                // demo shows a register nobody is obliged to use — the state EG-12 actually shipped.
                'is_default' => true,
                'is_active' => true,
                'notes' => 'Rent collections and supplier payments.',
            ],
        );

        $this->nbeAccount = BankAccount::updateOrCreate(
            ['asset_id' => $asset->id, 'account_number' => '900-8007-001122'],
            [
                'name' => 'NBE — service-charge account',
                'bank_name' => 'National Bank of Egypt',
                'iban' => 'EG210003000600000000123456789',
                'currency' => 'EGP',
                'ledger_account_id' => $leaf('11102003', 'NBE — Service Charge Account', 'حساب البنك الأهلي المصري — الخدمات')->id,
                // Service-charge money is still the operator's working cash — a second OPERATING
                // account, not a second purpose. It is deliberately not the default: two accounts
                // with one default is the situation the whole design is about, and a demo where
                // every account is a default demonstrates nothing.
                'purpose' => BankAccount::PURPOSE_OPERATING,
                'is_default' => false,
                'is_active' => true,
                'notes' => 'Service-charge collections, kept separate for the owner reconciliation.',
            ],
        );

        // A tenant's security deposit is money the operator HOLDS — `deposits_held` is a liability,
        // not working cash — and an Egyptian mall that keeps it apart banks it apart. Its own
        // purpose AND its own default, so a deposit receipt fills itself in with THIS account while
        // a rent receipt beside it fills in with CIB: the purpose ladder doing visible work, which
        // two operating accounts could never show.
        $this->depositAccount = BankAccount::updateOrCreate(
            ['asset_id' => $asset->id, 'account_number' => '900-8007-004488'],
            [
                'name' => 'NBE — tenant deposits',
                'bank_name' => 'National Bank of Egypt',
                'iban' => 'EG210003000600000000998877665',
                'currency' => 'EGP',
                'ledger_account_id' => $leaf('11102004', 'NBE — Tenant Deposits Account', 'حساب البنك الأهلي المصري — تأمينات المستأجرين')->id,
                'purpose' => BankAccount::PURPOSE_DEPOSITS,
                'is_default' => true,
                'is_active' => true,
                'notes' => 'Security deposits held on behalf of tenants, kept out of working cash.',
            ],
        );

        $this->command->info('   Seeded 3 bank accounts (CIB operating, NBE service charge, NBE deposits) on their own chart leaves');
    }

    /**
     * Rails whose money is actually IN the bank on the document's own date.
     *
     * Deliberately not "everything that is not cash". A card capture debits the bank the day it is
     * captured while the money lands T+1/T+2 (longer for Fawry), and a cheque lands when it clears —
     * that timing gap is the known-wrong thing {@see PaymentMethod} documents and the
     * reason a clearing account per rail is the eventual fix. Naming a bank account on those
     * documents would make the demo actively misleading: `MatchBankStatementLineService` finds
     * candidates BY the chart account, so every card capture would be offered against a CIB
     * statement line dated days before the money arrived, and the operator's first lesson from the
     * reconciliation screen would be a wrong match.
     *
     * @var array<int, string>
     */
    private const DEMO_SETTLED_RAILS = ['bank_transfer', 'instapay', 'bank'];

    /**
     * Which bank a demo document went through — alternating, and null wherever the money is not
     * yet in the bank.
     *
     * Alternating rather than all-one, because one account demonstrates nothing: the register, the
     * column, the filter and the reconciliation matcher only become legible once two banks hold
     * different money. Cash and the deferred rails stay null, which is the honest state and also
     * the one the floor covers.
     */
    /**
     * The account a document of this PURPOSE banks in — resolved through the app's own ladder.
     *
     * `BankAccount::defaultFor()` rather than a second rule written here, so the demo cannot show an
     * arrangement the running system would not produce: a bug in the ladder (a deposit receipt
     * quietly landing in working cash, say) shows up on the demo books instead of hiding behind
     * seeder-specific wiring. The demo has no payroll account, so the payroll run falls to the
     * operating default — which is the fallback being demonstrated, not a gap.
     */
    private function demoBankAccountForPurpose(string $purpose, ?int $assetId): ?int
    {
        return BankAccount::defaultFor($assetId, $purpose)?->id;
    }

    private function demoBankAccountFor(string $method, int $seq): ?int
    {
        if ($this->cibAccount === null || $this->nbeAccount === null) {
            return null;
        }

        if (! in_array($method, self::DEMO_SETTLED_RAILS, true)) {
            return null;
        }

        return $seq % 2 === 0 ? $this->cibAccount->id : $this->nbeAccount->id;
    }

    /**
     * Security-deposit ledger (module 21 source): a deposit receipt at
     * commencement for a spread of active leases, plus one partial refund and one
     * forfeit for workflow variety. tenant_id / asset_id are derived from the
     * lease by the model, so only lease_id is supplied.
     */
    private function seedSecurityDeposits(): void
    {
        $leases = Lease::where('status', 'active')
            ->where('security_deposit', '>', 0)
            ->orderByRaw('(id * 17) % 101')
            ->limit(8)
            ->get();

        $count = 0;
        foreach ($leases as $lease) {
            DepositTransaction::create([
                'lease_id' => $lease->id,
                'type' => 'receipt',
                'amount' => (float) $lease->security_deposit,
                'transaction_date' => $lease->commencement_date,
                'method' => 'bank',
                'bank_account_id' => $this->demoBankAccountForPurpose(BankAccount::PURPOSE_DEPOSITS, $lease->asset_id),
                'status' => 'recorded',
                'notes' => 'Security deposit received on lease commencement.',
            ]);
            $count++;
        }

        // One partial refund and one forfeit for variety.
        if ($leases->count() >= 2) {
            DepositTransaction::create([
                'lease_id' => $leases[0]->id,
                'type' => 'refund',
                'amount' => round((float) $leases[0]->security_deposit * 0.25, 2),
                'transaction_date' => Carbon::now()->subDays(10),
                'method' => 'bank',
                'bank_account_id' => $this->demoBankAccountForPurpose(BankAccount::PURPOSE_DEPOSITS, $leases[0]->asset_id),
                'status' => 'recorded',
                'notes' => 'Partial deposit refund after fit-out damage settlement.',
            ]);
            DepositTransaction::create([
                'lease_id' => $leases[1]->id,
                'type' => 'forfeit',
                'amount' => round((float) $leases[1]->security_deposit * 0.10, 2),
                'transaction_date' => Carbon::now()->subDays(6),
                'method' => 'bank',
                'bank_account_id' => $this->demoBankAccountForPurpose(BankAccount::PURPOSE_DEPOSITS, $leases[1]->asset_id),
                'status' => 'recorded',
                'notes' => 'Portion forfeited against unpaid utilities on exit.',
            ]);
            $count += 2;
        }

        $this->command->info("   Seeded {$count} security-deposit transactions (receipts + refund + forfeit)");
    }

    /**
     * Post-dated cheque register (module 33): tenants hand over forward-dated cheques the operator
     * banks on maturity. Seed a spread across the whole lifecycle so the register, the maturity
     * scan, and the settle-on-clear flow all have live data. Runs BEFORE the GL sync so the cleared
     * cheque's Payment gets posted.
     */
    private function seedPostDatedCheques(Asset $asset): void
    {
        $leases = Lease::whereHas('unit', fn ($q) => $q->where('asset_id', $asset->id))
            ->where('status', 'active')
            ->with('tenant')
            ->take(5)
            ->get();

        if ($leases->isEmpty()) {
            return;
        }

        $service = app(PostDatedChequeService::class);
        $actor = User::where('email', 'admin@mall.test')->first();
        $banks = ['CIB', 'QNB Alahli', 'Banque Misr', 'NBE', 'AAIB'];
        // [cheque_date offset in days, lifecycle action]
        $plan = [
            [-15, 'held'],      // matured but still held → the maturity scan surfaces it
            [-5,  'deposited'], // presented to the bank, awaiting clearance
            [-3,  'cleared'],   // funds received → a Payment settles the invoice + posts to the GL
            [-8,  'bounced'],   // deposited then returned unpaid
            [30,  'held'],      // not yet due
        ];
        $count = 0;

        foreach ($leases as $i => $lease) {
            [$offset, $action] = $plan[$i] ?? [0, 'held'];

            $invoice = Invoice::where('lease_id', $lease->id)
                ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
                ->where('balance', '>', 0)
                ->orderBy('due_date')
                ->first();

            $cheque = PostDatedCheque::create([
                'reference' => PostDatedCheque::generateReference(),
                'asset_id' => $asset->id,
                'tenant_id' => $lease->tenant_id,
                'lease_id' => $lease->id,
                'invoice_id' => $invoice?->id,
                'cheque_number' => 'CHQ-'.now()->format('y').'-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT),
                'bank_name' => $banks[$i % count($banks)],
                'amount' => $invoice ? min((float) $invoice->balance, 25000) : 15000,
                'currency' => 'EGP',
                'cheque_date' => now()->addDays($offset)->toDateString(),
                'received_date' => now()->subDays(20)->toDateString(),
                'status' => PostDatedCheque::STATUS_HELD,
            ]);
            $count++;

            if ($action === 'deposited') {
                $service->deposit($cheque);
            } elseif ($action === 'cleared' && $actor) {
                $service->clear($cheque, $actor, now()->subDays(2)->toDateString());
            } elseif ($action === 'bounced') {
                $service->deposit($cheque);
                $service->bounce($cheque);
            }
        }

        // A whole year of monthly cheques lodged up front (the Egyptian norm) — so the register
        // shows a real series and the "Lodge a series" feature isn't invisible on a fresh demo.
        $seriesLease = $leases->first();
        if ($seriesLease) {
            $series = $service->lodgeSeries([
                'asset_id' => $asset->id,
                'tenant_id' => $seriesLease->tenant_id,
                'lease_id' => $seriesLease->id,
                'bank_name' => 'CIB',
                'first_cheque_number' => '900100',
                'amount' => 25000,
                'count' => 12,
                'interval_months' => 1,
                'first_cheque_date' => now()->addMonthNoOverflow()->startOfMonth()->toDateString(),
                'received_date' => now()->subDays(20)->toDateString(),
            ]);
            $count += $series->count();
        }

        $this->command->info("   Seeded {$count} post-dated cheques (incl. a 12-cheque annual series)");
    }

    /**
     * Owner statements (module 32): the accrual-basis statement + disbursement the operator produces
     * for the property owner each month. Generate one FINALISED run for a fully-elapsed month and one
     * DRAFT for last month, so both boards have rows. Reads the posted GL, so it runs AFTER the sync.
     */
    private function seedOwnerStatements(Asset $asset): void
    {
        $owner = User::where('email', 'owner@atriom.test')->first();
        if (! $owner || ! $asset->propertyOwners()->where('users.id', $owner->id)->exists()) {
            return;
        }

        try {
            $calendar = app(FiscalCalendar::class);
            $calendar->ensureYear((int) now()->year);
            $calendar->ensureYear((int) now()->subYear()->year); // in case sub-2-months crosses January

            $generate = app(GenerateOwnerStatementRunService::class);
            $finalise = app(FinaliseOwnerStatementRunService::class);

            // Finalised statement for two months ago (fully elapsed, GL posted).
            $priorPeriod = AccountingPeriod::forDate(now()->subMonthsNoOverflow(2)->startOfMonth());
            if ($priorPeriod) {
                $finalise->finalise($generate->generate($asset, $priorPeriod), $owner);
            }

            // A draft for last month so the review queue isn't empty.
            $lastPeriod = AccountingPeriod::forDate(now()->subMonthNoOverflow()->startOfMonth());
            if ($lastPeriod && (! $priorPeriod || $lastPeriod->id !== $priorPeriod->id)) {
                $generate->generate($asset, $lastPeriod);
            }

            $this->command->info('   Seeded owner statements ('.OwnerStatementRun::count().' run(s), 1 finalised + 1 draft)');
        } catch (\Throwable $e) {
            // Owner statements are demo polish — never let a period/GL edge abort the whole seed.
            $this->command->warn('   Owner statements skipped: '.$e->getMessage());
        }

        $this->seedOwnerRequests($asset);
    }

    /**
     * One owner request with a real back-and-forth, so the conversation thread + reply count are
     * visible on a fresh demo (the module was otherwise empty).
     */
    private function seedOwnerRequests(Asset $asset): void
    {
        $owner = User::where('email', 'owner@atriom.test')->first();
        $operator = User::where('email', 'manager@mall.test')->first();
        if (! $owner || ! $operator) {
            return;
        }

        $svc = app(OwnerRequestService::class);
        $request = $svc->create([
            'asset_id' => $asset->id,
            'recipient' => 'operator',
            'subject' => 'Facade repair budget split',
            'body' => 'Please confirm the 60/40 cost split for the north-facade waterproofing before we sign off.',
            'priority' => 'high',
        ], $owner);

        // The conversation: operator acknowledges (moves to in-progress), owner nudges, operator resolves.
        $svc->reply($request, $operator, 'Received — reviewing the contractor quote, will confirm within two days.', 'in_progress');
        $svc->reply($request->refresh(), $owner, 'Thanks. We need it settled before the board meeting on Sunday.');
        $svc->reply($request->refresh(), $operator, 'Confirmed: 60/40 as proposed. Work order raised.', 'resolved');

        $this->command->info('   Seeded 1 owner request with a 3-message conversation');
    }

    /**
     * Units that were SOLD rather than let — مُلّاك الوحدات (module 37).
     *
     * The demo had no unit owners at all, so the whole module opened onto an empty state: the
     * register, the assessment run, and every figure that now counts an owner's arrears had nothing
     * to show. A feature that only exists in the test suite is a feature nobody looks at.
     *
     * **All four owner states are represented**, because each is a different money flow and the
     * screens read differently for each — an owner-occupier with no lease at all, one who let the
     * unit himself, one in the operator's rental pool, and one sitting empty and still owing صيانة.
     * Plus the two shapes that are easy to get wrong and impossible to picture from a single row: a
     * CO-OWNED unit whose 60/40 split must sum to one assessment, and a RESOLD unit whose register
     * carries both the former owner and the current one.
     *
     * The assessments are billed by `BillUnitOwnershipsService`, not inserted — same rule as the
     * leasing cycle above. Demo data an operator could not have produced hides the bugs a seeder
     * exists to surface.
     */
    private function seedUnitOwnerships(Asset $asset): void
    {
        $this->command->info('🔑 Seeding unit owners — sold units, assessments, a co-ownership and a resale…');

        // Units nobody leased. Taken from the tail of the vacant set so the occupancy figures above
        // stay exactly as they were — a sold unit is not vacant space, but it is not leased either.
        $units = Unit::where('asset_id', $asset->id)
            ->whereDoesntHave('allLeases')
            ->orderBy('code')
            ->take(5)
            ->get();

        if ($units->count() < 5) {
            $this->command->warn('   Not enough unleased units to seed unit owners — skipped.');

            return;
        }

        // Buyers are PEOPLE here, not companies: an Egyptian mall sells to individual investors, and
        // `tenants.party_type` is what lets one table hold both them and the retailers.
        $buyers = collect([
            ['name' => 'Ashraf El-Gindy', 'national_id' => '27801152300123'],
            ['name' => 'Hoda Serageldin', 'national_id' => '28203094500456'],
            ['name' => 'Yassin Abdel Rahman', 'national_id' => '27509281100789'],
            ['name' => 'Nagwa Fahmy', 'national_id' => '28810170200321'],
            ['name' => 'Sherif Mansour', 'national_id' => '27412030700654'],
            ['name' => 'Dalia Hafez', 'national_id' => '29006221300987'],
        ])->map(fn (array $b) => Tenant::updateOrCreate(
            ['email' => Str::slug($b['name']).'@owners.test'],
            [
                'name' => $b['name'],
                'legal_name' => $b['name'],
                'type' => 'individual',
                'party_type' => PartyType::UnitOwner->value,
                'national_id' => $b['national_id'],
                'status' => 'active',
                'phone' => '+2010'.mt_rand(10000000, 99999999),
                'address' => 'Cairo',
                'address_governorate' => 'Cairo',
            ],
        ));

        $soldOn = Carbon::now()->startOfMonth()->subMonths(8);

        // ── The four states, one unit each ────────────────────────────────────────────────────
        $states = [
            [UnitManagementMode::SelfOccupied,    'he trades from it himself', null],
            [UnitManagementMode::SelfLet,         'he found his own tenant',   null],
            [UnitManagementMode::OperatorManaged, 'we let it for him',         7.5],
            [UnitManagementMode::Vacant,          'bought and standing empty', null],
        ];

        foreach ($states as $i => [$mode, $note, $feePct]) {
            $this->makeOwnership($asset, $units[$i], $buyers[$i], $soldOn, [
                'management_mode' => $mode->value,
                'notes' => "Sold {$soldOn->format('M Y')} — {$note}.",
                'management_fee_pct' => $feePct,
                'fee_basis' => $feePct === null ? null : ManagementFeeBasis::Collected->value,
            ]);
        }

        // ── A CO-OWNED unit: two owners, 60/40 ────────────────────────────────────────────────
        // Between them they pay for the unit exactly once. Worth having in the demo because a
        // reviewer cannot tell from one row whether the shares are applied or ignored.
        $shared = $units[4];
        $this->makeOwnership($asset, $shared, $buyers[4], $soldOn, [
            'ownership_share_pct' => 60,
            'notes' => 'Co-owned 60/40 with Dalia Hafez.',
        ]);
        $this->makeOwnership($asset, $shared, $buyers[5], $soldOn, [
            'ownership_share_pct' => 40,
            'notes' => 'Co-owned 40/60 with Sherif Mansour.',
        ]);

        // ── Six months of assessments, then the resale, then the rest ────────────────────────
        // ORDER IS THE POINT and the first cut got it wrong. Applying the resale BEFORE the
        // back-billing marked the seller `transferred`, and `isBillableForPeriod` only bills a
        // HANDED-OVER ownership — so he ended up with a closed tenure and NOT ONE assessment
        // against it. That is data no operator could have produced: in life he is billed monthly
        // while he owns the unit, and only then does he sell. It also quietly defeated the reason
        // the seller's row is kept at all — there was no history left for it to be the basis of.
        //
        // So: bill the months he owned it, transfer, bill the months the buyer owns it.
        $run = app(BillUnitOwnershipsService::class);

        for ($m = 6; $m >= 3; $m--) {
            $run->runForPeriod(CarbonImmutable::now()->startOfMonth()->subMonths($m), $asset->id);
        }

        // ── The RESALE ────────────────────────────────────────────────────────────────────────
        // The register keeps BOTH rows: the seller's tenure is CLOSED with a date, never deleted,
        // or every assessment he was billed loses its basis. This is what makes the "Current owners
        // only" filter on the list screen mean something.
        $resoldOn = CarbonImmutable::now()->startOfMonth()->subMonths(2);
        UnitOwnership::where('unit_id', $units[0]->id)
            ->whereNull('ended_at')
            ->first()
            ?->update([
                'ended_at' => $resoldOn->subDay()->toDateString(),
                'status' => UnitOwnershipStatus::Transferred->value,
            ]);

        $this->makeOwnership($asset, $units[0], $buyers[3], Carbon::parse($resoldOn->toDateString()), [
            'management_mode' => UnitManagementMode::SelfOccupied->value,
            'notes' => 'Bought from Ashraf El-Gindy — resale, second owner of this unit.',
        ]);

        for ($m = 2; $m >= 0; $m--) {
            $run->runForPeriod(CarbonImmutable::now()->startOfMonth()->subMonths($m), $asset->id);
        }

        $owned = UnitOwnership::where('asset_id', $asset->id)->count();
        $assessments = Invoice::whereNotNull('unit_ownership_id')->count();
        $this->command->info("   {$owned} ownerships over 5 units (incl. a co-ownership and a resale), {$assessments} assessments billed");
    }

    /** One ownership plus its صيانة schedule — the shape the admin form produces. */
    private function makeOwnership(Asset $asset, Unit $unit, Tenant $owner, Carbon $from, array $attrs = []): UnitOwnership
    {
        $ownership = UnitOwnership::create(array_merge([
            'asset_id' => $asset->id,
            'unit_id' => $unit->id,
            'tenant_id' => $owner->id,
            'status' => UnitOwnershipStatus::HandedOver->value,
            'tenure_type' => UnitTenureType::Freehold->value,
            'started_at' => $from->toDateString(),
            'handover_date' => $from->toDateString(),
            'purchase_date' => $from->copy()->subMonths(2)->toDateString(),
            'purchase_contract_number' => 'SALE-'.strtoupper(Str::random(6)),
            // Roughly EGP 45k/m² — a plausible Cairo strip-mall number, and it makes the
            // purchase-value assessment basis show a sane figure if anyone switches to it.
            'purchase_price' => round((float) $unit->area_sqm * 45000, 2),
            'payment_terms_days' => 14,
        ], $attrs));

        // The صيانة itself. Per m² per month — the basis the register defaults to, so the demo
        // shows the default working rather than a hand-picked number.
        Charge::create([
            'unit_ownership_id' => $ownership->id,
            'name' => 'Service charge',
            'type' => 'service_charge',
            'amount' => round((float) $unit->area_sqm * 55, 2),
            'currency' => 'EGP',
            'frequency' => 'monthly',
            'vat_applicable' => true,
            'is_active' => true,
            'start_date' => $from->toDateString(),
        ]);

        return $ownership;
    }
}
