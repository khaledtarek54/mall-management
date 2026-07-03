<?php

namespace Database\Seeders;

use App\Enums\TenantRequestType;
use App\Models\Asset;
use App\Models\CamExpensePool;
use App\Models\Charge;
use App\Models\CreditNote;
use App\Models\CreditNoteItem;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\MeterReading;
use App\Models\Note;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\TenantSalesDeclaration;
use App\Models\Unit;
use App\Models\User;
use App\Models\UtilityMeter;
use App\Models\Vendor;
use App\Models\VendorContact;
use App\Models\VendorContract;
use App\Services\CamReconciliationService;
use App\Services\Eta\EtaSubmissionService;
use App\Services\PercentageRentCalculationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
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
                \App\Models\TenantUser::create([
                    'tenant_id' => $tenant->id,
                    'name' => $tenantData['contact'] ?? 'Tenant Admin',
                    'email' => $portalEmail,
                    'password' => $demoPassword,
                    'is_admin' => true,
                ]);

                if ($i === 0) {
                    \App\Models\TenantUser::create([
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
        $this->seedVendors($atriomWalk);
        $this->seedMaintenanceRequests();
        $this->seedTenantSalesDeclarations();
        $this->seedCamReconciliation($atriomWalk);
        $this->seedEtaSubmissions();
        $this->seedUtilityMeters($atriomWalk);
        $this->seedTenantNotes();
        $this->seedCreditNotes();
        $this->seedStaffAssignments($atriomWalk);

        // Auto-provision a budget for every property (current year), re-derive
        // them from billed levy items, then record a few demo spends.
        \Illuminate\Support\Facades\Artisan::call('marketing:ensure-budgets');
        \Illuminate\Support\Facades\Artisan::call('marketing:backfill-budgets');
        $this->seedMarketingSpends();
        $this->command->info('   Marketing budgets auto-provisioned + derived + demo spends');

        $plazaUnitCount = Unit::where('asset_id', $plazaAnnex->id)->count();
        $this->command->info("✅ Created Atriom Walk with {$occupiedCount} occupied, {$vacantCount} vacant units (+ {$plazaUnitCount} vacant units on Plaza Annex demo asset)");
        $this->command->info('✅ Generated leases, charges, invoices, and payment history');
        $this->command->newLine();
        $this->command->info('📊 Demo metrics (Atriom Walk):');
        $this->command->info('   Occupancy: '.$atriomWalk->fresh()->occupancyRate().'%');
        $this->command->info('   Total leases: '.Lease::count());
        $this->command->info('   Total invoices: '.Invoice::count());
        $this->command->info('   Outstanding AR: EGP '.number_format(Invoice::whereIn('status', ['issued', 'partially_paid', 'overdue'])->sum('balance'), 2));
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
    private function seedMaintenanceRequests(): void
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

                $declaration = TenantSalesDeclaration::create([
                    'lease_id' => $lease->id,
                    'period_start' => $periodStart,
                    'period_end' => $periodEnd,
                    'declared_sales' => $sales,
                    'declared_at' => $periodEnd->copy()->addDays(3),
                    'declared_by_type' => Tenant::class,
                    'declared_by_id' => $lease->tenant_id,
                    'status' => 'submitted', // start as submitted; lock below if status === 'locked'
                ]);

                $service->recalculate($declaration);
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
            'status' => 'reconciling',
            'notes' => 'Includes security, cleaning, common-area HVAC, lobby lighting, landscaping.',
        ]);

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
                $amount = (float) $lease->base_rent_monthly + (float) $lease->service_charge_monthly;
                $vat = round($amount * 0.14, 2);
                $total = round($amount + $vat, 2);
                $dueDate = $now->copy()->subDays($bucket['days']);

                Invoice::create([
                    'number' => Invoice::generateNumber('AW', $dueDate),
                    'lease_id' => $lease->id,
                    'tenant_id' => $lease->tenant_id,
                    'status' => $bucket['days'] > 0 ? 'overdue' : 'issued',
                    'issue_date' => $dueDate->copy()->subDays(7),
                    'due_date' => $dueDate,
                    'period_start' => $dueDate->copy()->startOfMonth(),
                    'period_end' => $dueDate->copy()->endOfMonth(),
                    'subtotal' => $amount,
                    'vat_amount' => $vat,
                    'total' => $total,
                    'paid_amount' => 0,
                    'balance' => $total,
                    'currency' => 'EGP',
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
                'contract' => ['name' => 'HVAC maintenance — annual', 'value' => 360000, 'start' => '2026-01-01', 'end' => '2026-12-31'],
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
                'contract' => ['name' => 'On-call plumbing — SLA', 'value' => 90000, 'start' => '2026-01-01', 'end' => '2026-12-31'],
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
            ],
            [
                'name' => 'PestStop Egypt',
                'type' => 'service_provider',
                'email' => 'support@peststop.eg',
                'phone' => '+201557788992',
                'city' => 'Cairo',
                'contact' => ['name' => 'Tarek Sami', 'role' => 'Operations', 'phone' => '+201557788992'],
                'contract' => ['name' => 'Quarterly pest control', 'value' => 60000, 'start' => '2026-01-01', 'end' => '2026-12-31'],
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
            $vendor = Vendor::updateOrCreate(
                ['email' => $v['email']],
                [
                    'name' => $v['name'],
                    'type' => $v['type'],
                    'status' => 'active',
                    'tax_id' => $v['tax_id'] ?? null,
                    'phone' => $v['phone'],
                    'city' => $v['city'],
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
            $service = app(\App\Services\CreditNoteService::class);
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

        foreach (\App\Models\MarketingBudget::all() as $budget) {
            foreach ($samples as $i => $s) {
                $amount = round((float) $budget->accrued_amount * $s['frac'], 2);
                if ($amount <= 0) {
                    continue;
                }
                \App\Models\MarketingSpend::create([
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
}
