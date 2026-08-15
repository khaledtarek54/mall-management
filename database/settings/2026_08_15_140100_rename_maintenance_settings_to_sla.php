<?php

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Two settings groups stop calling themselves "maintenance".
 *
 * The SLA hours move to a `sla` group rather than to either module's name, because BOTH read
 * them — `TenantRequestService` for tenant requests, `SlaResolver` for facility work orders.
 * Naming the group after one of the two would rebuild the same confusion in a new place, which
 * is the whole thing this rename exists to remove.
 *
 * The module toggle `modules.maintenance` gated tenant requests and never touched facility work,
 * so it becomes `modules.requests` — matching the permissions, the slug, the API routes and the
 * `requests:*` commands, all of which already used that word.
 */
return new class extends SettingsMigration
{
    /** old property path => new property path */
    private const RENAMES = [
        'maintenance.sla_urgent_hours' => 'sla.sla_urgent_hours',
        'maintenance.sla_high_hours' => 'sla.sla_high_hours',
        'maintenance.sla_medium_hours' => 'sla.sla_medium_hours',
        'maintenance.sla_low_hours' => 'sla.sla_low_hours',
        'maintenance.sla_urgent_respond_hours' => 'sla.sla_urgent_respond_hours',
        'maintenance.sla_high_respond_hours' => 'sla.sla_high_respond_hours',
        'maintenance.sla_medium_respond_hours' => 'sla.sla_medium_respond_hours',
        'maintenance.sla_low_respond_hours' => 'sla.sla_low_respond_hours',
        'modules.maintenance' => 'modules.requests',
    ];

    public function up(): void
    {
        foreach (self::RENAMES as $from => $to) {
            if ($this->migrator->exists($from)) {
                $this->migrator->rename($from, $to);
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::RENAMES, true) as $from => $to) {
            if ($this->migrator->exists($to)) {
                $this->migrator->rename($to, $from);
            }
        }
    }
};
