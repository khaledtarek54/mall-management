<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionRegistrar;

/**
 * **The tenant-request module stops calling itself "maintenance".**
 *
 * The table rename happened on 2026-06-29 (`maintenance_requests` → `tenant_requests`) and the
 * model, service, API routes and activity log name followed. Four identifiers did not, and they
 * are the ones an operator actually reads: the permission strings behind the Requests screen, the
 * module toggle, the settings group behind the SLA tab, and the type stamped into every bell row.
 *
 * The word still means four other things in this system and all four are correct English for what
 * they are — a unit "under maintenance", the `maintenance` expense category, the `maintenance`
 * request TYPE, and the facility-maintenance module. None of them is touched here.
 *
 * Data only. The code side of the rename ships in the same commit.
 */
return new class extends Migration
{
    /** old => new. The 7 permissions behind the Requests screen. */
    private const PERMISSIONS = [
        'maintenance.view' => 'requests.view',
        'maintenance.create' => 'requests.create',
        'maintenance.edit' => 'requests.edit',
        'maintenance.delete' => 'requests.delete',
        'maintenance.assign' => 'requests.assign',
        'maintenance.view_all' => 'requests.view_all',
        'maintenance.change_status' => 'requests.change_status',
    ];

    public function up(): void
    {
        $this->renamePermissions(self::PERMISSIONS);

        // The two settings groups move in the settings migration beside this one
        // (2026_08_15_140100), through spatie's own migrator rather than raw SQL.

        $this->renameNotifications(
            'App\\Notifications\\PortalMaintenanceSubmittedNotification',
            'App\\Notifications\\PortalRequestSubmittedNotification',
            ['portal_maintenance_submitted' => 'portal_request_submitted'],
        );

        // Raised by ScanTenantRequestSlaBreachesCommand; the class name did not change, only the
        // `type` discriminator inside the payload that the bell reads to pick a deep link.
        $this->renameNotificationDataTypes(['maintenance_sla_breached' => 'request_sla_breached']);
    }

    public function down(): void
    {
        $this->renamePermissions(array_flip(self::PERMISSIONS));
        $this->renameNotifications(
            'App\\Notifications\\PortalRequestSubmittedNotification',
            'App\\Notifications\\PortalMaintenanceSubmittedNotification',
            ['portal_request_submitted' => 'portal_maintenance_submitted'],
        );
        $this->renameNotificationDataTypes(['request_sla_breached' => 'maintenance_sla_breached']);
    }

    /**
     * `role_has_permissions` keys on `permission_id`, so renaming `name` carries every grant with
     * it — no re-granting, and no window where a role holds nothing.
     *
     * @param  array<string, string>  $map
     */
    private function renamePermissions(array $map): void
    {
        if (! Schema::hasTable('permissions')) {
            return;
        }

        foreach ($map as $old => $new) {
            DB::table('permissions')->where('name', $old)->update(['name' => $new]);
        }

        // spatie caches the whole catalogue; without this the app keeps answering from the old one
        // until the cache happens to expire, which reads as "the rename half-worked".
        if (app()->bound(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }

    /**
     * Laravel stores the notification CLASS in `notifications.type`; the bell's own discriminator
     * lives inside the JSON `data`. Both move, or an existing row renders as an unknown type.
     *
     * @param  array<string, string>  $dataTypes
     */
    private function renameNotifications(string $fromClass, string $toClass, array $dataTypes): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        DB::table('notifications')->where('type', $fromClass)->update(['type' => $toClass]);
        $this->renameNotificationDataTypes($dataTypes);
    }

    /**
     * Rewrites `data->type` without a JSON function, so this behaves identically on MySQL and on
     * the sqlite the suite runs against (where `data` is plain text).
     *
     * @param  array<string, string>  $map
     */
    private function renameNotificationDataTypes(array $map): void
    {
        if (! Schema::hasTable('notifications')) {
            return;
        }

        foreach ($map as $old => $new) {
            DB::table('notifications')
                ->where('data', 'like', '%"type":"'.$old.'"%')
                ->orderBy('id')
                ->chunkById(200, function ($rows) use ($old, $new) {
                    foreach ($rows as $row) {
                        DB::table('notifications')->where('id', $row->id)->update([
                            'data' => str_replace('"type":"'.$old.'"', '"type":"'.$new.'"', $row->data),
                        ]);
                    }
                });
        }
    }
};
