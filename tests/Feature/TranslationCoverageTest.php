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
            'users.roles_list' => ['super_admin', 'manager', 'viewer', 'owner', 'leasing', 'operations'],

            // Status enums (mirror the migration enum lists)
            'statuses.lease' => ['draft', 'pending_approval', 'active', 'expired', 'renewed', 'terminated', 'cancelled'],
            'statuses.invoice' => ['draft', 'issued', 'partially_paid', 'paid', 'overdue', 'disputed', 'cancelled', 'credited'],
            'statuses.payment' => ['initiated', 'authorized', 'captured', 'reconciled', 'settled', 'failed', 'refunded', 'bounced', 'voided'],
            'statuses.unit' => ['vacant', 'reserved', 'occupied', 'maintenance'],
            'statuses.tenant' => ['active', 'inactive', 'blacklisted'],
            'statuses.tenant_request' => ['submitted', 'acknowledged', 'in_progress', 'awaiting_tenant', 'resolved', 'closed', 'cancelled'],
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
            'enums.work_category' => ['electrical', 'plumbing', 'hvac', 'structural', 'cleaning', 'safety', 'other'],
            'enums.request_channel' => ['portal', 'whatsapp', 'phone', 'email', 'walk_in', 'admin'],
            'enums.work_priority' => ['low', 'medium', 'high', 'urgent'],
            'enums.category' => ['retail', 'food_beverage', 'wellness', 'service', 'kiosk', 'office', 'storage'],
            'enums.asset_type' => ['mall', 'retail_walk', 'mixed_use', 'office', 'residential'],
            'enums.meter_type' => ['electric', 'water', 'gas'],
            'enums.invoice_item_type' => ['base_rent', 'service_charge', 'utility', 'parking', 'percentage_rent', 'marketing', 'late_fee', 'cam_recovery', 'other'],
            'enums.credit_note_reason' => ['return', 'dispute', 'adjustment', 'discount', 'refund', 'other'],
            'enums.vendor_type' => ['contractor', 'supplier', 'service_provider', 'consultant', 'other'],

            // Spatie ActivityLog default events
            'activity.events' => ['created', 'updated', 'deleted'],

            // Activity subjects — must include every model that uses LogsActivity.
            // Derived from `useLogName()` calls across app/Models/.
            'activity.subjects' => [
                'lease', 'invoice', 'payment', 'tenant', 'charge', 'asset',
                'tenant_request', 'tenant_sales', 'cam_pool',
                'credit_note', 'vendor', 'vendor_contract', 'note',
                'access_control',
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
            "Missing translation keys (would render as raw 'admin.x.y.z' strings in the UI):\n  - ".implode("\n  - ", $missing),
        );
    }

    /** Every key must exist in BOTH locales — no EN-only or AR-only keys. */
    public function test_en_ar_key_parity_across_all_lang_files(): void
    {
        $diffs = [];
        foreach (['admin', 'api', 'auth', 'errors', 'pay'] as $f) {
            $en = $this->flatten(require base_path("lang/en/$f.php"));
            $ar = $this->flatten(require base_path("lang/ar/$f.php"));
            foreach (array_keys(array_diff_key($en, $ar)) as $k) {
                $diffs[] = "AR missing: $f.$k";
            }
            foreach (array_keys(array_diff_key($ar, $en)) as $k) {
                $diffs[] = "EN missing: $f.$k";
            }
        }

        $this->assertEmpty($diffs, "EN/AR translation key parity broken:\n  - ".implode("\n  - ", $diffs));
    }

    /** Every __('ns.key') referenced in the code must resolve (leaf or array group). */
    public function test_referenced_translation_keys_exist(): void
    {
        $ns = ['admin', 'api', 'auth', 'errors', 'pay'];
        $leaves = [];
        foreach ($ns as $f) {
            foreach (array_keys($this->flatten(require base_path("lang/en/$f.php"))) as $k) {
                $leaves["$f.$k"] = true;
            }
        }
        $leafKeys = array_keys($leaves);
        $resolves = function (string $key) use ($leaves, $leafKeys): bool {
            if (isset($leaves[$key])) {
                return true;
            }
            $prefix = $key.'.';
            foreach ($leafKeys as $lk) {
                if (str_starts_with($lk, $prefix)) {
                    return true; // an array group (e.g. enums.method)
                }
            }

            return false;
        };

        $missing = [];
        foreach (['app', 'resources/views'] as $dir) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($dir))) as $file) {
                if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.php')) {
                    continue;
                }
                if (preg_match_all('/(?:__|trans|@lang|Lang::get)\(\s*[\'"]([a-zA-Z0-9_.\-]+)[\'"]/', file_get_contents($file->getPathname()), $m)) {
                    foreach ($m[1] as $key) {
                        if (! in_array(explode('.', $key)[0], $ns, true) || str_ends_with($key, '.')) {
                            continue; // framework namespace or dynamic key
                        }
                        if (! $resolves($key)) {
                            $missing[$key] = true;
                        }
                    }
                }
            }
        }

        $this->assertEmpty(
            array_keys($missing),
            "Translation keys referenced in code but missing from lang files (render raw):\n  - ".implode("\n  - ", array_keys($missing)),
        );
    }

    private function flatten(array $a, string $prefix = ''): array
    {
        $out = [];
        foreach ($a as $k => $v) {
            $key = $prefix === '' ? (string) $k : "$prefix.$k";
            if (is_array($v)) {
                $out += $this->flatten($v, $key);
            } else {
                $out[$key] = $v;
            }
        }

        return $out;
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
