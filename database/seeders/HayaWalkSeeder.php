<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Charge;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Lease;
use App\Models\MaintenanceRequest;
use App\Models\MaintenanceRequestComment;
use App\Models\Payment;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;

class HayaWalkSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🏬 Seeding Haya Walk demo data...');

        // 0. Admin + role-demo users (all share password 'password')
        $users = [
            ['email' => 'admin@mall.test',   'name' => 'Mall Admin',       'role' => 'super_admin'],
            ['email' => 'manager@mall.test', 'name' => 'Operations Manager', 'role' => 'manager'],
            ['email' => 'viewer@mall.test',  'name' => 'Property Auditor', 'role' => 'viewer'],
        ];
        foreach ($users as $u) {
            $user = User::updateOrCreate(
                ['email' => $u['email']],
                ['name' => $u['name'], 'password' => Hash::make('password')],
            );
            $user->syncRoles([$u['role']]);
        }

        // 1. The Asset
        $hayaWalk = Asset::create([
            'name' => 'Haya Walk',
            'code' => 'HW',
            'type' => 'retail_walk',
            'address' => 'Wahat Road, 6th of October City',
            'city' => '6th of October',
            'country' => 'Egypt',
            'total_area_sqm' => 12000,
            'leasable_area_sqm' => 8500,
            'currency' => 'EGP',
            'metadata' => [
                'owner' => 'Jawad Developments',
                'launched' => '2025',
            ],
        ]);

        // 2. Define unit layout — 50 units across 3 zones (A, B, C)
        $units = $this->unitLayout();

        // 3. Define tenants — community retail walk mix
        $tenants = $this->tenantList();

        $occupiedCount = 0;
        $vacantCount = 0;

        foreach ($units as $i => $unitData) {
            $unit = Unit::create([
                'asset_id' => $hayaWalk->id,
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

            // First three tenants get portal-login creds (tenant1/2/3@haya.test / password)
            $portalEmail = $i < 3
                ? 'tenant' . ($i + 1) . '@haya.test'
                : ($tenantData['email'] ?? strtolower(str_replace(' ', '', $tenantData['name'])) . '@example.com');

            $tenant = Tenant::create([
                'name' => $tenantData['name'],
                'legal_name' => $tenantData['legal'] ?? $tenantData['name'] . ' LLC',
                'type' => 'company',
                'email' => $portalEmail,
                'password' => $i < 3 ? Hash::make('password') : null,
                'phone' => '+201' . rand(100000000, 999999999),
                'whatsapp' => '+201' . rand(100000000, 999999999),
                'tax_id' => (string) rand(100000000, 999999999),
                'contact_person' => $tenantData['contact'] ?? 'Owner',
                'status' => 'active',
            ]);

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
                'reference' => Lease::generateReference('HW'),
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

        $this->seedCurrentMonthPayments();
        $this->seedArAgingSpread();
        $this->seedMaintenanceRequests();

        $this->command->info("✅ Created Haya Walk with {$occupiedCount} occupied, {$vacantCount} vacant units");
        $this->command->info("✅ Generated leases, charges, invoices, and payment history");
        $this->command->newLine();
        $this->command->info('📊 Demo metrics:');
        $this->command->info('   Occupancy: ' . $hayaWalk->fresh()->occupancyRate() . '%');
        $this->command->info('   Total leases: ' . Lease::count());
        $this->command->info('   Total invoices: ' . Invoice::count());
        $this->command->info('   Outstanding AR: EGP ' . number_format(Invoice::whereIn('status', ['issued', 'partially_paid', 'overdue'])->sum('balance'), 2));
    }

    /**
     * Realistic mall maintenance: one urgent, one in-progress, one awaiting tenant,
     * one resolved, one closed. Spreads across the three portal tenants so any
     * /portal login has something to see.
     */
    private function seedMaintenanceRequests(): void
    {
        $tenants = Tenant::whereIn('email', [
            'tenant1@haya.test',
            'tenant2@haya.test',
            'tenant3@haya.test',
        ])->with(['leases.unit'])->get()->keyBy('email');

        $manager = User::where('email', 'manager@mall.test')->first();
        $admin = User::where('email', 'admin@mall.test')->first();

        if ($tenants->isEmpty() || ! $manager) {
            return;
        }

        $seedData = [
            // tenant1 — Café Crema (A-01) — urgent + open
            [
                'tenant_email' => 'tenant1@haya.test',
                'title' => 'AC unit blowing warm air',
                'description' => 'Customer area AC has been blowing warm air since yesterday morning. With the heat, we are losing sit-down customers. Need urgent fix.',
                'category' => 'hvac',
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
                'tenant_email' => 'tenant1@haya.test',
                'title' => 'Front signage light flickering',
                'description' => 'The Café Crema sign at the entrance flickers at night.',
                'category' => 'electrical',
                'priority' => 'medium',
                'submitted_days_ago' => 22,
                'resolved_days_ago' => 19,
                'status' => 'resolved',
                'assign_to' => $manager,
                'resolution_notes' => 'Replaced faulty driver and two LED modules. Verified at night.',
            ],

            // tenant2 — Optix Eyewear (A-02) — awaiting tenant
            [
                'tenant_email' => 'tenant2@haya.test',
                'title' => 'Leak from ceiling near display cases',
                'description' => 'There is water dripping from one of the ceiling tiles near our front display. We placed a bucket but need this checked before stock is damaged.',
                'category' => 'plumbing',
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
                'tenant_email' => 'tenant2@haya.test',
                'title' => 'Door auto-closer too tight',
                'description' => 'Glass door is hard to push for elderly customers.',
                'category' => 'structural',
                'priority' => 'low',
                'submitted_days_ago' => 60,
                'resolved_days_ago' => 55,
                'closed_days_ago' => 48,
                'status' => 'closed',
                'assign_to' => $manager,
                'resolution_notes' => 'Loosened spring tension. Customer confirmed door now opens easily.',
            ],

            // tenant3 — The Burger Joint — acknowledged, just opened
            [
                'tenant_email' => 'tenant3@haya.test',
                'title' => 'Fire alarm beeping every 2 minutes',
                'description' => 'The small fire-alarm sensor near the kitchen has been beeping every couple of minutes since this morning. Probably low battery. We did not touch it.',
                'category' => 'safety',
                'priority' => 'high',
                'submitted_days_ago' => 0,
                'status' => 'acknowledged',
                'assign_to' => $manager,
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

            $request = MaintenanceRequest::create([
                'reference' => MaintenanceRequest::generateReference($unit->asset->code),
                'tenant_id' => $tenant->id,
                'unit_id' => $unit->id,
                'lease_id' => $lease->id,
                'status' => 'submitted',
                'priority' => $row['priority'],
                'category' => $row['category'],
                'title' => $row['title'],
                'description' => $row['description'],
                'submitted_at' => $submittedAt,
                'target_resolution_at' => $submittedAt->copy()->addHours($slaHours),
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

            foreach ($row['comments'] ?? [] as $c) {
                $author = $c['by'] === 'manager' ? $manager : ($c['by'] === 'admin' ? $admin : $tenant);
                MaintenanceRequestComment::create([
                    'maintenance_request_id' => $request->id,
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

        $this->command->info("   Seeded {$created} maintenance requests");
    }

    /**
     * Seed a handful of payments dated in the current month so the
     * "Collected This Month" KPI and MoM delta look healthy in the demo.
     */
    private function seedCurrentMonthPayments(int $count = 7): void
    {
        $now = Carbon::now();

        $invoices = Invoice::where('balance', '>', 0)
            ->inRandomOrder()
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

        $leases = Lease::where('status', 'active')->inRandomOrder()->limit(10)->get();
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
                    'number' => Invoice::generateNumber('HW', $dueDate),
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
                'number' => Invoice::generateNumber('HW', $issueDate),
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
                'description' => 'Monthly Rent - ' . $period->format('F Y'),
                'type' => 'base_rent',
                'amount' => $rent,
                'vat_rate' => 0,
                'vat_amount' => 0,
                'total' => $rent,
            ]);

            InvoiceItem::create([
                'invoice_id' => $invoice->id,
                'description' => 'Service Charge - ' . $period->format('F Y'),
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

    private function tenantList(): array
    {
        return [
            ['name' => 'Café Crema', 'contact' => 'Ahmed Hassan'],
            ['name' => 'Optix Eyewear', 'contact' => 'Mona Sherif'],
            ['name' => 'The Burger Joint', 'contact' => 'Karim Adel'],
            ['name' => 'El Doctor Pharmacy', 'contact' => 'Dr. Sara Mahmoud'],
            ['name' => 'Marina Patisserie', 'contact' => 'Pierre Khouri'],
            ['name' => 'Stylo Salon', 'contact' => 'Nada Fahmy'],
            ['name' => 'Sushi Lab', 'contact' => 'Yuki Tanaka'],
            ['name' => 'Zara Express', 'contact' => 'Layla Mostafa'],
            ['name' => 'Bambini Italian Kitchen', 'contact' => 'Marco Rossi'],
            ['name' => 'Mobile World', 'contact' => 'Tarek Saad'],
            // B Zone
            ['name' => 'Pretty Petals Florist', 'contact' => 'Rania Habib'],
            ['name' => 'Cairo Booksellers', 'contact' => 'Omar El-Sayed'],
            ['name' => 'Quick Cuts Barber', 'contact' => 'Hassan Aly'],
            ['name' => 'Toy Galaxy', 'contact' => 'Heba Mostafa'],
            ['name' => 'Glow Beauty Lounge', 'contact' => 'Salma Adel'],
            ['name' => 'Lush Cosmetics', 'contact' => 'Yara Wahby'],
            ['name' => 'Speedy Laundry', 'contact' => 'Ibrahim Naguib'],
            ['name' => 'Vintage Closet', 'contact' => 'Dina Rashed'],
            ['name' => 'Pet Paradise', 'contact' => 'Khaled Yousef'],
            ['name' => 'Shoe Atelier', 'contact' => 'Sherif Eldin'],
            ['name' => 'Home Essentials', 'contact' => 'Marwa Salem'],
            ['name' => 'Tech Hub Electronics', 'contact' => 'Amr Kamel'],
            ['name' => 'FlexFit Gym', 'contact' => 'Coach Mido'],
            ['name' => 'Quick Dry Cleaners', 'contact' => 'Wael Hosni'],
            ['name' => 'Kids Wonderland', 'contact' => 'Hala Ismail'],
            // C Zone
            ['name' => 'Wellness Spa Sanctuary', 'contact' => 'Dr. Sherif Hany'],
            ['name' => 'Roastery Coffee Co.', 'contact' => 'Ali Mahmoud'],
            ['name' => 'Modern Mart', 'contact' => 'Fatma Zaki'],
            ['name' => 'Andiamo Italian', 'contact' => 'Antonio Bianchi'],
            ['name' => 'Pure Yoga Studio', 'contact' => 'Maya Salah'],
            ['name' => 'ATM Branch — NBE', 'contact' => 'Branch Manager'],
            ['name' => 'Sunset Sunglasses', 'contact' => 'Ramy Adel'],
            ['name' => 'The Grill House', 'contact' => 'Chef Hossam'],
            // 43rd tenant onwards — leaving some vacant
        ];
    }
}
