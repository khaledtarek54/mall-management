<?php

namespace Database\Seeders;

use App\Models\Asset;
use App\Models\Floor;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * A DELIBERATELY EMPTY MALL — the smallest database in which the whole system still works.
 *
 * **Why this exists.** `DemoSeeder` seeds a mall mid-life: 50 units, 43 leases, months of invoices,
 * payments, CAM reconciliations and a GL with history. That is the right dataset for a demo and the
 * wrong one for learning, because every number on every screen was put there by somebody else — you
 * cannot tell what YOUR action changed. This seeder seeds the reference data the system cannot run
 * without, one property, empty units and a few tenants, and then **stops**.
 *
 * What you get:
 *   - reference data — the identical four seeders `atriom:install` runs (roles + 182 permissions,
 *     the approval ladder, departments, and the accounting spine: chart of accounts, account
 *     mappings, tax codes, charge codes, an open fiscal calendar). Without the accounting half a
 *     database **bills perfectly and posts nothing** — see InstallCommand's docblock.
 *   - one property (Atriom Walk / AW) with two floors and **12 vacant units**
 *   - **3 tenants, no leases, no charges, no invoices, no payments, no journal entries**
 *
 * So the first lease you create is the first lease that ever existed here, and the first invoice
 * you generate is the entire accounts-receivable ledger. Every figure on every screen is yours.
 *
 * **Run it:**  `php artisan migrate:fresh --seed --seeder=Database\\Seeders\\LearningSeeder`
 * **Re-run it:** `php artisan db:seed --class=LearningSeeder` — idempotent (updateOrCreate
 * throughout), so it re-asserts the reference data and the empty property without touching a lease
 * you created. It never deletes: to get back to zero, run the `migrate:fresh` form again.
 */
class LearningSeeder extends Seeder
{
    /**
     * The teaching mall. Twelve units is enough to lease a few, expand into one, and still see
     * vacancy; the areas are varied because per-m² rent and CAM pro-rata shares are only legible
     * when the units differ. A-12 at 200 m² is the worked example in the leasing walkthrough.
     *
     * @var list<array{code: string, floor: string, category: string, area: float}>
     */
    private const UNITS = [
        ['code' => 'A-01', 'floor' => 'Ground', 'category' => 'retail',        'area' => 60],
        ['code' => 'A-02', 'floor' => 'Ground', 'category' => 'retail',        'area' => 75],
        ['code' => 'A-03', 'floor' => 'Ground', 'category' => 'retail',        'area' => 90],
        ['code' => 'A-04', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 120],
        ['code' => 'A-05', 'floor' => 'Ground', 'category' => 'food_beverage', 'area' => 150],
        ['code' => 'A-06', 'floor' => 'Ground', 'category' => 'kiosk',         'area' => 15],
        ['code' => 'A-07', 'floor' => 'Ground', 'category' => 'service',       'area' => 45],
        ['code' => 'A-12', 'floor' => 'Ground', 'category' => 'retail',        'area' => 200],
        ['code' => 'B-01', 'floor' => '1',      'category' => 'retail',        'area' => 100],
        ['code' => 'B-02', 'floor' => '1',      'category' => 'retail',        'area' => 110],
        ['code' => 'B-03', 'floor' => '1',      'category' => 'office',        'area' => 130],
        ['code' => 'B-04', 'floor' => '1',      'category' => 'wellness',      'area' => 180],
    ];

    /**
     * Three retailers with no lease between them — a leasing pipeline, not a rent roll.
     * Zara is the worked example; Cilantro is the F&B case (percentage rent, food-court CAM);
     * Nike is the second tenant you need to prove property isolation and the double-booking guard.
     *
     * @var list<array{name: string, legal: string, email: string, contact: string, portal: bool}>
     */
    private const TENANTS = [
        ['name' => 'Zara Egypt',   'legal' => 'Zara Retail Egypt LLC',   'email' => 'zara@atriomwalk.test',     'contact' => 'Mona Adel',    'portal' => true],
        ['name' => 'Cilantro',     'legal' => 'Cilantro Cafes Egypt SAE', 'email' => 'cilantro@atriomwalk.test', 'contact' => 'Karim Fouad',  'portal' => false],
        ['name' => 'Nike Egypt',   'legal' => 'Nike Trading Egypt LLC',  'email' => 'nike@atriomwalk.test',     'contact' => 'Sara Mahmoud', 'portal' => false],
    ];

    /**
     * Staff logins that can operate on the property. Assignment is not decoration: a non-super_admin
     * with no row in `asset_user` resolves to an empty visible set, so every page 404s for them and
     * the role is untestable (the exact bug that hid the auditor login in DemoSeeder).
     *
     * @var list<array{email: string, name: string, role: string, staff: bool}>
     */
    private const USERS = [
        ['email' => 'admin@mall.test',      'name' => 'Mall Admin',         'role' => 'super_admin', 'staff' => false],
        ['email' => 'manager@mall.test',    'name' => 'Operations Manager', 'role' => 'manager',     'staff' => true],
        ['email' => 'leasing@mall.test',    'name' => 'Leasing Manager',    'role' => 'leasing',     'staff' => true],
        ['email' => 'accounting@mall.test', 'name' => 'Accounting Lead',    'role' => 'accounting',  'staff' => true],
        ['email' => 'viewer@mall.test',     'name' => 'Property Auditor',   'role' => 'viewer',      'staff' => true],
        // The owner reaches the property through `asset_owner`, not the staff pivot.
        ['email' => 'owner@atriom.test',    'name' => 'Property Owner',     'role' => 'owner',       'staff' => false],
    ];

    public function run(): void
    {
        // ── 1. Reference data ──────────────────────────────────────────────────────────────────
        // The same SEVEN, in the same order, that `atriom:install` runs on a real first deploy.
        // AccountingSeeder is last because a charge code names the tax code it bills under. Both
        // UtilityTariffSeeder and HolidaySeeder are portfolio-wide (`asset_id` null), so neither
        // needs the property this seeder creates below — and without them the empty-mall variant
        // shipped no tariff catalogue and a BLANK working calendar, which is not "the same
        // reference data `atriom:install` lays down" however the comment above it read.
        $this->call([
            RolesPermissionsSeeder::class,
            ApprovalRulesSeeder::class,
            DepartmentSeeder::class,
            UtilityTariffSeeder::class,
            PaymentMethodSeeder::class,
            HolidaySeeder::class,
            AccountingSeeder::class,
        ]);

        $password = Hash::make((string) env('DEMO_USER_PASSWORD', 'password'));

        // ── 2. Logins ──────────────────────────────────────────────────────────────────────────
        $users = [];
        foreach (self::USERS as $row) {
            $user = User::updateOrCreate(
                ['email' => $row['email']],
                ['name' => $row['name'], 'password' => $password],
            );
            $user->syncRoles([$row['role']]);
            $users[$row['email']] = $user;
        }

        // ── 3. The property ────────────────────────────────────────────────────────────────────
        // `leasable_area_sqm` is the EXACT sum of the units below, so every pro-rata share (CAM,
        // marketing, service charge) divides into a clean number you can check by hand.
        $leasable = collect(self::UNITS)->sum('area');

        $asset = Asset::updateOrCreate(
            ['code' => 'AW'],
            [
                'name' => 'Atriom Walk',
                'type' => 'retail_walk',
                'address' => 'Wahat Road, 6th of October City',
                'city' => '6th of October',
                'country' => 'Egypt',
                'total_area_sqm' => 1800,
                'leasable_area_sqm' => $leasable,
                'currency' => 'EGP',
                'is_active' => true,
                'metadata' => ['owner' => 'Atriom Developments', 'launched' => '2026'],
            ],
        );

        $asset->propertyOwners()->syncWithoutDetaching([
            $users['owner@atriom.test']->id => [
                'ownership_percentage' => 100,
                'started_at' => now()->startOfYear()->toDateString(),
            ],
        ]);

        foreach (self::USERS as $row) {
            if ($row['staff']) {
                $asset->staff()->syncWithoutDetaching([
                    $users[$row['email']]->id => ['assigned_at' => now()->toDateString()],
                ]);
            }
        }

        // ── 4. Floors + vacant units ───────────────────────────────────────────────────────────
        foreach (self::UNITS as $row) {
            Unit::updateOrCreate(
                ['asset_id' => $asset->id, 'code' => $row['code']],
                [
                    'floor_id' => $this->floor($asset, $row['floor'])->id,
                    'category' => $row['category'],
                    'area_sqm' => $row['area'],
                    'status' => 'vacant',
                ],
            );
        }

        // ── 5. Tenants, with no lease between them ─────────────────────────────────────────────
        foreach (self::TENANTS as $i => $row) {
            $tenant = Tenant::updateOrCreate(
                ['email' => $row['email']],
                [
                    'name' => $row['name'],
                    'legal_name' => $row['legal'],
                    'type' => 'company',
                    'password' => $row['portal'] ? $password : null,
                    'phone' => '+2010000000'.($i + 1),
                    'tax_id' => '10000000'.($i + 1),
                    // The receiver address in the parts the tax authority validates — filled so an
                    // e-invoice submission is possible later without editing the tenant first.
                    'address' => 'Atriom Walk, 6th of October City',
                    'address_governorate' => 'Giza',
                    'address_city' => '6th of October City',
                    'address_street' => 'Wahat Road',
                    'address_building_number' => (string) (10 + $i),
                    'contact_person' => $row['contact'],
                    'status' => 'active',
                ],
            );

            if ($row['portal']) {
                TenantUser::updateOrCreate(
                    ['email' => $row['email']],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $row['contact'],
                        'password' => $password,
                        'is_admin' => true,
                    ],
                );
            }
        }

        $this->report($asset);
    }

    /**
     * The property's floor register — created once, then selected by everything that stands on it.
     * Same derivation DemoSeeder uses, so a unit seeded here and a unit seeded there land on the
     * same floor codes.
     */
    private function floor(Asset $asset, string $label): Floor
    {
        [$code, $level] = match (strtolower($label)) {
            'basement', 'b1' => ['B1', -1],
            'ground', 'g' => ['G', 0],
            'mezzanine', 'm' => ['M', 1],
            default => [(string) (int) $label, (int) $label],
        };

        return Floor::firstOrCreate(
            ['asset_id' => $asset->id, 'code' => $code],
            ['name' => $label, 'level' => $level],
        );
    }

    private function report(Asset $asset): void
    {
        $this->command?->newLine();
        $this->command?->info('🧪 A fresh, empty Atriom Walk is ready.');
        $this->command?->table(
            ['What', 'Count'],
            [
                ['Property', $asset->name.' ('.$asset->code.') — '.number_format((float) $asset->leasable_area_sqm).' m² leasable'],
                ['Units', Unit::where('asset_id', $asset->id)->count().' — all VACANT'],
                ['Tenants', Tenant::count().' — none of them leases anything yet'],
                ['Leases / charges / invoices / payments / journal entries', '0 — that part is yours to create'],
            ],
        );
        $this->command?->line('   Admin login: <fg=cyan>admin@mall.test</> · password <fg=cyan>password</>');
        $this->command?->line('   Tenant portal: <fg=cyan>zara@atriomwalk.test</> · password <fg=cyan>password</>');
        $this->command?->newLine();
    }
}
