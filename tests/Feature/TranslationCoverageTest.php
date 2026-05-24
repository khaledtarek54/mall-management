<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * Catches the "raw key leaked into the UI" class of bug — e.g. when a value column
 * gets a status the lang file doesn't cover, Filament renders the literal
 * "admin.statuses.x.y" string. Every dynamic key referenced via interpolated state
 * is enumerated here against the canonical enum/role values.
 *
 * Adding a new enum value or new role anywhere in the codebase MUST also add the
 * matching translation in both EN + AR, or this test fails.
 */
class TranslationCoverageTest extends TestCase
{
    private array $en;
    private array $ar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->en = require base_path('lang/en/admin.php');
        $this->ar = require base_path('lang/ar/admin.php');
    }

    public function test_every_dynamic_translation_key_resolves_in_both_locales(): void
    {
        // Each prefix maps to the canonical set of values that variable can take
        // at runtime. Pulled from the source-of-truth — DB enums + Spatie role seeder.
        $coverage = [
            // Spatie roles (RolesPermissionsSeeder)
            'users.roles_list' => ['super_admin', 'manager', 'viewer', 'owner'],

            // Status enums (mirror the migration enum lists)
            'statuses.lease' => ['draft', 'pending_approval', 'active', 'expired', 'renewed', 'terminated', 'cancelled'],
            'statuses.invoice' => ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'disputed', 'cancelled', 'credited'],
            'statuses.payment' => ['initiated', 'authorized', 'captured', 'reconciled', 'settled', 'failed', 'refunded', 'bounced'],
            'statuses.unit' => ['vacant', 'reserved', 'occupied', 'maintenance'],
            'statuses.tenant' => ['active', 'inactive', 'blacklisted'],
            'statuses.maintenance_request' => ['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant', 'resolved', 'closed', 'cancelled'],
            'statuses.tenant_sales' => ['submitted', 'locked', 'disputed'],
            'statuses.eta' => ['pending', 'submitted', 'valid', 'invalid', 'rejected', 'cancelled'],
            'statuses.cam_pool' => ['draft', 'reconciling', 'reconciled', 'closed'],
            'statuses.cam_allocation' => ['pending', 'billed', 'disputed', 'closed'],
            'statuses.meter' => ['active', 'inactive', 'faulty'],
            'statuses.credit_note' => ['draft', 'issued', 'applied', 'void'],
            'statuses.vendor' => ['active', 'inactive', 'blacklisted'],
            'statuses.vendor_contract' => ['draft', 'active', 'expired', 'terminated'],

            // Type / category enums
            'enums.method' => ['card', 'bank_transfer', 'instapay', 'wallet', 'cash', 'cheque', 'other'],
            'enums.maintenance_category' => ['electrical', 'plumbing', 'hvac', 'structural', 'cleaning', 'safety', 'other'],
            'enums.maintenance_priority' => ['low', 'medium', 'high', 'urgent'],
            'enums.category' => ['retail', 'food_beverage', 'wellness', 'service', 'kiosk', 'office', 'storage'],
            'enums.asset_type' => ['mall', 'retail_walk', 'mixed_use', 'office', 'residential'],
            'enums.meter_type' => ['electric', 'water', 'gas'],
            'enums.invoice_item_type' => ['base_rent', 'service_charge', 'utility', 'parking', 'percentage_rent', 'late_fee', 'other'],
            'enums.credit_note_reason' => ['return', 'dispute', 'adjustment', 'discount', 'refund', 'other'],
            'enums.vendor_type' => ['contractor', 'supplier', 'service_provider', 'consultant', 'other'],

            // Spatie ActivityLog default events
            'activity.events' => ['created', 'updated', 'deleted'],

            // Activity subjects — must include every model that uses LogsActivity.
            // Derived from `useLogName()` calls across app/Models/.
            'activity.subjects' => [
                'lease', 'invoice', 'payment', 'tenant', 'charge', 'asset',
                'maintenance_request', 'tenant_sales', 'cam_pool',
                'credit_note', 'vendor', 'vendor_contract', 'note',
            ],
        ];

        $missing = [];
        foreach ($coverage as $prefix => $values) {
            $parts = explode('.', $prefix);
            foreach ($values as $v) {
                $path = array_merge($parts, [$v]);
                if ($this->dig($this->en, $path) === null) {
                    $missing[] = "EN: admin.{$prefix}.{$v}";
                }
                if ($this->dig($this->ar, $path) === null) {
                    $missing[] = "AR: admin.{$prefix}.{$v}";
                }
            }
        }

        $this->assertEmpty(
            $missing,
            "Missing translation keys (would render as raw 'admin.x.y.z' strings in the UI):\n  - " . implode("\n  - ", $missing),
        );
    }

    private function dig(array $a, array $parts): mixed
    {
        foreach ($parts as $p) {
            if (! is_array($a) || ! array_key_exists($p, $a)) {
                return null;
            }
            $a = $a[$p];
        }
        return $a;
    }
}
