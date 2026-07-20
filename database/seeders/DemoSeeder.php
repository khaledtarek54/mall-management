<?php

namespace Database\Seeders;

use App\Enums\TenantRequestType;
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Department;
use App\Models\DepositTransaction;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\Equipment;
use App\Models\FixedAsset;
use App\Models\InventoryItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\JournalEntry;
use App\Models\Lease;
use App\Models\LeaseCamTerm;
use App\Models\MaintenancePlan;
use App\Models\MaintenanceWorkOrder;
use App\Models\MaintenanceWorkOrderItem;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Models\MeterReading;
use App\Models\Note;
use App\Models\Payment;
use App\Models\Payroll;
use App\Models\PayrollLine;
use App\Models\PostDatedCheque;
use App\Models\SlaPolicy;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\TenantSalesDeclaration;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Models\Warehouse;
use App\Services\CamReconciliationService;
use App\Services\CreditNoteService;
use App\Services\DepreciationService;
use App\Services\DisposeFixedAssetService;
use App\Services\Eta\EtaSubmissionService;
use App\Services\GeneratePreventiveWorkOrdersService;
use App\Services\MaintenanceWorkOrderService;
use App\Services\RaiseCorrectiveMaintenanceService;
use App\Services\GrantCustodyService;
use App\Services\GrantEmployeeAdvanceService;
use App\Services\PayrollService;
use App\Services\PercentageRentCalculationService;
use App\Services\PostDatedChequeService;
use App\Services\RecordAdvanceRepaymentService;
use App\Services\SettleCustodyService;
use App\Services\StockMovementService;
use App\Services\VendorBillService;
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

    public function run(): void
    {
        mt_srand(self::DEMO_RNG_SEED);

        $this->command->info('🏬 Seeding Atriom Walk demo data...');

        // Demo password lives in env so production deploys can rotate
        // without touching the seeder (audit M17 F-63 / D-48). Default
        // 'password' matches DEMO.md for dev + CI. Pre-pilot deploys
        // MUST override DEMO_USER_PASSWORD and trigger a first-login
        // rotation when the URL becomes public.
        $demoPassword = Hash::make((string) env('DEMO_USER_PASSWORD', 'password'));

        // 0. Admin + role-demo users (all share the demo password above)
        $users = [
            ['email' => 'admin@mall.test',       'name' => 'Mall Admin',           'role' => 'super_admin'],
            ['email' => 'manager@mall.test',     'name' => 'Operations Manager',   'role' => 'manager'],
            ['email' => 'viewer@mall.test',      'name' => 'Property Auditor',     'role' => 'viewer'],
            ['email' => 'owner@atriom.test',     'name' => 'Property Owner',       'role' => 'owner'],
            ['email' => 'leasing@mall.test',     'name' => 'Leasing Manager',      'role' => 'leasing'],
            ['email' => 'maintenance@mall.test', 'name' => 'Maintenance Manager',  'role' => 'operations'],
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
            ['code' => 'AW'],
            [
                'name' => 'Atriom Walk',
                'type' => 'retail_walk',
                'address' => 'Wahat Road, 6th of October City',
                'city' => '6th of October',
                'country' => 'Egypt',
                'total_area_sqm' => 12000,
                'leasable_area_sqm' => 8500,
                'currency' => 'EGP',
                'metadata' => [
                    'owner' => 'Atriom Developments',
                    'launched' => '2025',
                ],
            ],
        );

        // Attach the owner user to Atriom Walk at 100% ownership
        $ownerUser = User::where('email', 'owner@atriom.test')->first();
        if ($ownerUser) {
            $atriomWalk->owners()->syncWithoutDetaching([
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
            ['code' => 'PA'],
            [
                'name' => 'Plaza Annex',
                'type' => 'retail_walk',
                'address' => 'Plaza Road, 6th of October City',
                'city' => '6th of October',
                'country' => 'Egypt',
                'total_area_sqm' => 2200,
                'leasable_area_sqm' => 1600,
                'currency' => 'EGP',
                'is_active' => true,
                'metadata' => ['owner' => 'Atriom Developments', 'launched' => '2026', 'notes' => 'Strip annex; scoping demo asset.'],
            ],
        );
        foreach (range(1, 8) as $n) {
            Unit::updateOrCreate(
                ['asset_id' => $plazaAnnex->id, 'code' => sprintf('PA-%02d', $n)],
                [
                    'floor' => 'Ground',
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
                'floor' => $unitData['floor'],
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
                ? 'tenant'.($i + 1).'@atriomwalk.test'
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
                'contact_person' => $tenantData['contact'] ?? 'Owner',
                'status' => 'active',
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
                        'email' => 'staff1@atriomwalk.test',
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
                'reference' => Lease::generateReference('AW'),
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
                'security_deposit_received' => true,
                'escalation_rate' => 7.00,
                'escalation_type' => 'fixed_percent',
                'next_escalation_date' => $commencement->copy()->addYear(),
                'has_percentage_rent' => in_array($unitData['category'], ['retail', 'food_beverage']),
                'percentage_rent_threshold' => in_array($unitData['category'], ['retail', 'food_beverage']) ? $rent * 5 : null,
                'percentage_rent_rate' => in_array($unitData['category'], ['retail', 'food_beverage']) ? 6.00 : null,
                'percentage_rent_calculation_type' => in_array($unitData['category'], ['retail', 'food_beverage']) ? 'artificial' : null,
                'payment_terms_days' => 7,
            ]);

            // Update unit status
            $unit->update(['status' => 'occupied']);
            $occupiedCount++;

            // Create charges
            Charge::create([
                'lease_id' => $lease->id,
                'name' => 'Base Rent',
                'type' => 'base_rent',
                'amount' => $rent,
                'frequency' => 'monthly',
                'vat_applicable' => false, // rent typically VAT-exempt in Egypt
                'vat_rate' => 0,
                'is_active' => true,
            ]);

            Charge::create([
                'lease_id' => $lease->id,
                'name' => 'Service Charge',
                'type' => 'service_charge',
                'amount' => $service,
                'frequency' => 'monthly',
                'vat_applicable' => true,
                'vat_rate' => 14.00,
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
        $this->seedEtaSubmissions();
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

        // --- Operational + financial modules (22–26 + AP / expenses / deposits) ---
        $employees = $this->seedHrEmployees($atriomWalk);
        $this->seedTreasuryCustody($employees);
        $this->seedInventory($atriomWalk);
        $this->seedFixedAssets($atriomWalk);
        $this->seedPreventiveMaintenance($atriomWalk);
        $this->seedVendorBills($atriomWalk);
        $this->seedExpenses($atriomWalk);
        $this->seedSecurityDeposits();
        $this->seedPostDatedCheques($atriomWalk);

        $plazaUnitCount = Unit::where('asset_id', $plazaAnnex->id)->count();
        $this->command->info("✅ Created Atriom Walk with {$occupiedCount} occupied, {$vacantCount} vacant units (+ {$plazaUnitCount} vacant units on Plaza Annex demo asset)");
        $this->command->info('✅ Generated leases, charges, invoices, and payment history');
        $this->command->newLine();
        $this->command->info('📊 Demo metrics (Atriom Walk):');
        $this->command->info('   Occupancy: '.$atriomWalk->fresh()->occupancyRate().'%');
        $this->command->info('   Total leases: '.Lease::count());
        $this->command->info('   Total invoices: '.Invoice::count());
        $this->command->info('   Outstanding AR: EGP '.number_format(Invoice::whereIn('status', ['issued', 'partially_paid', 'overdue'])->sum('balance'), 2));

        // General Ledger (module 21): post the double-entry journal from EVERY
        // source document now that all of them exist. The sync sweep is windowed
        // (only posts documents in the recent window by default); `--all`
        // backfills the full history in one idempotent pass, so this must run
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
     * Guarantee each portal-login tenant (tenant1/2/3) has one fresh UNPAID
     * invoice, so the tenant-portal "Pay Now" demo always has something to pay
     * (the button only shows when balance > 0). Runs after the payment seeders
     * so nothing settles these.
     */
    private function seedPortalDemoInvoices(): void
    {
        $tenants = Tenant::whereIn('email', [
            'tenant1@atriomwalk.test',
            'tenant2@atriomwalk.test',
            'tenant3@atriomwalk.test',
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
                'vat_rate' => 14.00,
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
            'tenant1@atriomwalk.test',
            'tenant2@atriomwalk.test',
            'tenant3@atriomwalk.test',
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
            $slaHours = config("maintenance.sla.{$row['priority']}.resolve_hours", 168);
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
                    'declared_by_type' => Tenant::class,
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
     * Seed ETA submission history on a realistic slice of past invoices so the
     * Invoices table shows a mix of submitted / valid / unsubmitted rows on
     * first login. Runs the same mock submission path the admin action triggers.
     */
    private function seedEtaSubmissions(): void
    {
        $service = app(EtaSubmissionService::class);

        // Submit a slice: every 3rd issued/paid invoice. Mix of statuses
        // (the mock returns Valid; we manually flip a few to invalid/rejected
        // to demonstrate badge variety in the demo).
        $invoices = Invoice::query()
            ->whereIn('status', ['issued', 'partially_paid', 'paid', 'overdue'])
            ->orderBy('issue_date', 'desc')
            ->get();

        $submitted = 0;
        $rejected = 0;
        foreach ($invoices as $i => $invoice) {
            if ($i % 3 !== 0) {
                continue; // sparse — leaves ~2/3 unsubmitted for demo click target
            }

            $service->submit($invoice);

            // Every 7th submission, simulate a rejected status for badge variety
            if ($i % 21 === 0) {
                $invoice->fresh()->update([
                    'eta_status' => 'rejected',
                    'eta_response' => [
                        'status' => 'error',
                        'rejectedDocuments' => [['errors' => [['target' => 'totalAmount', 'message' => 'Mismatch in computed total']]]],
                    ],
                ]);
                $rejected++;
            } else {
                $submitted++;
            }
        }

        $this->command->info("   Seeded ETA submissions ({$submitted} valid + {$rejected} rejected; rest unsubmitted)");
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
                    'noteable_type' => Tenant::class,
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
                'method' => collect(['bank_transfer', 'instapay', 'card', 'cheque'])->random(),
                'status' => 'captured',
                'payment_date' => $payDate,
            ]);

            $invoice->payments()->attach($payment->id, ['allocated_amount' => round($amount, 2)]);

            $invoice->paid_amount = (float) $invoice->paid_amount + $amount;
            $invoice->balance = max(0, (float) $invoice->total - (float) $invoice->paid_amount);
            $invoice->status = $invoice->balance == 0 ? 'paid' : 'partially_paid';
            $invoice->save();

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
                    'type' => 'service_charge', 'amount' => $service, 'vat_rate' => 14.00, 'vat_amount' => $vat, 'total' => round($service + $vat, 2),
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

            $status = match (true) {
                $isPaid => 'paid',
                $isPartial => 'partially_paid',
                $dueDate->isPast() => 'overdue',
                default => 'issued',
            };

            $invoice = Invoice::create([
                'number' => Invoice::generateNumber('AW', $issueDate),
                'lease_id' => $lease->id,
                'tenant_id' => $tenant->id,
                'status' => $status,
                'issue_date' => $issueDate,
                'due_date' => $dueDate,
                'period_start' => $period->copy()->startOfMonth(),
                'period_end' => $period->copy()->endOfMonth(),
                'subtotal' => $subtotal,
                'vat_amount' => $vat,
                'total' => $total,
                'paid_amount' => $paidAmount,
                'balance' => $total - $paidAmount,
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
                'vat_rate' => 14.00,
                'vat_amount' => $vat,
                'total' => $service + $vat,
            ]);

            if ($paidAmount > 0) {
                $payment = Payment::create([
                    'reference' => Payment::generateReference(),
                    'tenant_id' => $tenant->id,
                    'amount' => $paidAmount,
                    'currency' => 'EGP',
                    'method' => collect(['card', 'bank_transfer', 'instapay', 'cash'])->random(),
                    'status' => 'captured',
                    'payment_date' => $dueDate->copy()->subDays(rand(0, 5)),
                ]);

                $invoice->payments()->attach($payment->id, ['allocated_amount' => $paidAmount]);
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
                    'coi_expires_at' => $coi['expires'] ?? null,
                    'insurer' => $coi['insurer'] ?? null,
                    'policy_number' => $coi['policy'] ?? null,
                ],
            );

            VendorContact::updateOrCreate(
                ['vendor_id' => $vendor->id, 'name' => $v['contact']['name']],
                [
                    'role' => $v['contact']['role'],
                    'email' => $v['email'],
                    'phone' => $v['contact']['phone'],
                    'is_primary' => true,
                ],
            );

            if ($v['contract']) {
                VendorContract::updateOrCreate(
                    ['vendor_id' => $vendor->id, 'name' => $v['contract']['name']],
                    [
                        'asset_id' => $asset->id,
                        'status' => 'active',
                        'start_date' => $v['contract']['start'],
                        'end_date' => $v['contract']['end'],
                        'value' => $v['contract']['value'],
                        'currency' => 'EGP',
                        // FR-CM-08 — SLA penalty terms, if this contract negotiated any.
                        // Per-day is the accruing basis, and the reason the penalty is
                        // re-assessed on every scan rather than computed once.
                        'sla_penalty_basis' => $v['contract']['penalty_basis'] ?? 'none',
                        'sla_penalty_rate' => $v['contract']['penalty_rate'] ?? 0,
                    ],
                );
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
        $assignments = [
            ['email' => 'manager@mall.test',     'role' => 'Operations Manager'],
            ['email' => 'leasing@mall.test',     'role' => 'Leasing Lead'],
            ['email' => 'maintenance@mall.test', 'role' => 'Facilities Supervisor'],
            ['email' => 'accounting@mall.test',  'role' => 'Accounting Lead'],
            ['email' => 'marketing@mall.test',   'role' => 'Marketing Lead'],
            ['email' => 'hr@mall.test',          'role' => 'HR Lead'],
        ];

        foreach ($assignments as $a) {
            $user = User::where('email', $a['email'])->first();
            if (! $user) {
                continue;
            }

            $asset->staff()->syncWithoutDetaching([
                $user->id => [
                    'role' => $a['role'],
                    'assigned_at' => now()->subMonths(6),
                ],
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

        $parts = Warehouse::create(['asset_id' => $asset->id, 'name' => 'Parts Store', 'code' => 'PST', 'category' => 'spare parts', 'is_active' => true]);
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
    private function seedPreventiveMaintenance(Asset $asset): void
    {
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
            ['title' => 'HVAC filter & coil service',      'category' => 'hvac',        'unit' => 'weeks',  'freq' => 2, 'due' => -6,  'dept' => $ops, 'vendor' => $coolAir,     'equip' => 'AHU-01',
                'checklist' => ['Inspect filter condition', 'Replace filter cartridge', 'Clean condenser coil', 'Check airflow pressure']],
            ['title' => 'Monthly fire-safety inspection',  'category' => 'fire-safety', 'unit' => 'months', 'freq' => 1, 'due' => -3,  'dept' => $ops, 'vendor' => $fireSafe,    'equip' => null,
                'checklist' => ['Inspect fire extinguishers', 'Test fire-alarm panel', 'Check emergency exits', 'Verify signage & lighting']],
            ['title' => 'Elevator quarterly maintenance',  'category' => 'elevator',    'unit' => 'months', 'freq' => 3, 'due' => -10, 'dept' => $ops, 'vendor' => null,         'equip' => 'LFT-01',
                'checklist' => ['Inspect cables & pulleys', 'Test brakes & governor', 'Check door mechanisms', 'Load test', 'Certify safety']],
            ['title' => 'Generator monthly test-run',      'category' => 'generator',   'unit' => 'months', 'freq' => 1, 'due' => -2,  'dept' => $ops, 'vendor' => $brightSpark, 'equip' => 'GEN-01',
                'checklist' => ['Check fuel & oil levels', 'Run under load 15 min', 'Inspect battery', 'Log readings']],
            // Yearly (FR-PPM-02) — the unit that used to fire monthly.
            ['title' => 'Chiller annual overhaul',         'category' => 'hvac',        'unit' => 'years',  'freq' => 1, 'due' => -1,  'dept' => $ops, 'vendor' => $coolAir,     'equip' => 'CH-01',
                'checklist' => ['Strip & inspect compressor', 'Replace refrigerant', 'Pressure-test circuit', 'Recommission']],
        ];

        $equipmentIds = Equipment::where('asset_id', $asset->id)->pluck('id', 'code');

        foreach ($plans as $p) {
            $equipmentId = $p['equip'] ? ($equipmentIds[$p['equip']] ?? null) : null;

            MaintenancePlan::create([
                'asset_id' => $asset->id,
                'unit_id' => null,
                'equipment_id' => $equipmentId,
                'title' => $p['title'],
                'category' => $p['category'],
                'maintenance_type' => $equipmentId
                    ? MaintenancePlan::MAINTENANCE_TYPE_FIXED
                    : MaintenancePlan::MAINTENANCE_TYPE_ROUTINE,
                'description' => $p['title'].' — preventive schedule.',
                'frequency_unit' => $p['unit'],
                'frequency_value' => $p['freq'],
                'checklist' => $p['checklist'],
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
        $engineer = User::where('email', 'maintenance@mall.test')->first();
        $wo = MaintenanceWorkOrder::where('status', 'open')->orderBy('scheduled_for')->first();
        if ($wo && $engineer) {
            $svc = app(MaintenanceWorkOrderService::class);
            $svc->transition($wo, 'in_progress', $engineer->id);

            // The last item fails — a PPM visit that finds a fault is the normal case,
            // and it's what corrective maintenance gets raised from (FR-CM-01). A fail
            // does not block closure; only an unchecked item does.
            $items = $wo->items()->orderBy('id')->get();
            foreach ($items as $i => $item) {
                $svc->markItem(
                    $item,
                    $items->count() > 1 && $i === $items->count() - 1
                        ? MaintenanceWorkOrderItem::RESULT_FAIL
                        : MaintenanceWorkOrderItem::RESULT_PASS,
                    $engineer->id,
                );
            }

            // The failed check raises corrective work (FR-CM-01) — the canonical flow: the
            // visit found a fault, the visit still closes, and the fix becomes its own job.
            $failed = $wo->items()->failed()->first();

            if ($failed) {
                app(RaiseCorrectiveMaintenanceService::class)->fromFailedCheck($failed, [
                    'execution_type' => MaintenanceWorkOrder::EXECUTION_EXTERNAL,
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
            app(RaiseCorrectiveMaintenanceService::class)->asFollowUp($wo->fresh(), [
                'execution_type' => MaintenanceWorkOrder::EXECUTION_INTERNAL,
                'assigned_to_user_id' => $engineer->id,
                'description' => 'Vendor closed the job but the fault recurred within a week — re-inspect and finish the fix.',
                'scheduled_for' => Carbon::now()->addDays(3)->toDateString(),
            ]);
        }

        $this->command->info('   Seeded '.count($plans)." preventive plans, {$created} work orders generated (1 completed)");
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
        $count = 0;

        foreach ($machines as [$code, $nameEn, $nameAr, $category, $location, $faTag, $subs]) {
            $parent = Equipment::create([
                'asset_id' => $asset->id,
                'code' => $code,
                'name_en' => $nameEn,
                'name_ar' => $nameAr,
                'category' => $category,
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
                    'category' => $category,
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
                'expense_date' => Carbon::now()->subDays($s['days'])->toDateString(),
                'status' => 'recorded',
            ]);
        }

        $this->command->info('   Seeded '.count($samples).' direct expenses');
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
                'status' => 'recorded',
                'notes' => 'Partial deposit refund after fit-out damage settlement.',
            ]);
            DepositTransaction::create([
                'lease_id' => $leases[1]->id,
                'type' => 'forfeit',
                'amount' => round((float) $leases[1]->security_deposit * 0.10, 2),
                'transaction_date' => Carbon::now()->subDays(6),
                'method' => 'bank',
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

        $this->command->info("   Seeded {$count} post-dated cheques (held / deposited / cleared / bounced)");
    }

    /**
     * Owner statements (module 32): the accrual-basis statement + disbursement the operator produces
     * for the property owner each month. Generate one FINALISED run for a fully-elapsed month and one
     * DRAFT for last month, so both boards have rows. Reads the posted GL, so it runs AFTER the sync.
     */
    private function seedOwnerStatements(Asset $asset): void
    {
        $owner = User::where('email', 'owner@atriom.test')->first();
        if (! $owner || ! $asset->owners()->where('users.id', $owner->id)->exists()) {
            return;
        }

        try {
            $calendar = app(\App\Services\Accounting\FiscalCalendar::class);
            $calendar->ensureYear((int) now()->year);
            $calendar->ensureYear((int) now()->subYear()->year); // in case sub-2-months crosses January

            $generate = app(\App\Services\OwnerAccounting\GenerateOwnerStatementRunService::class);
            $finalise = app(\App\Services\OwnerAccounting\FinaliseOwnerStatementRunService::class);

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

            $this->command->info('   Seeded owner statements ('.\App\Models\OwnerStatementRun::count().' run(s), 1 finalised + 1 draft)');
        } catch (\Throwable $e) {
            // Owner statements are demo polish — never let a period/GL edge abort the whole seed.
            $this->command->warn('   Owner statements skipped: '.$e->getMessage());
        }
    }
}
