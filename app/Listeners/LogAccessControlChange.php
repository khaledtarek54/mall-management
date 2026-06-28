<?php

namespace App\Listeners;

use App\Support\AccessControlAudit;
use Illuminate\Events\Dispatcher;
use Spatie\Permission\Events\RoleAttachedEvent;
use Spatie\Permission\Events\RoleDetachedEvent;
use Spatie\Permission\Models\Role;

/**
 * Audits user↔role grants. Spatie fires these events from assignRole /
 * removeRole / syncRoles — but ONLY when config('permission.events_enabled') is
 * true (we flip it on). The event payload is an array of role primary keys.
 *
 * Role↔permission changes are audited separately, directly in the Create/Edit
 * Role pages, because syncPermissions() bulk-detaches silently WITHOUT firing a
 * PermissionDetachedEvent — so an event listener would miss permission removals.
 */
class LogAccessControlChange
{
    public function onRoleAttached(RoleAttachedEvent $event): void
    {
        AccessControlAudit::log(
            $event->model,
            'role_granted',
            AccessControlAudit::namesFrom($event->rolesOrIds, Role::class),
        );
    }

    public function onRoleDetached(RoleDetachedEvent $event): void
    {
        AccessControlAudit::log(
            $event->model,
            'role_revoked',
            AccessControlAudit::namesFrom($event->rolesOrIds, Role::class),
        );
    }

    /**
     * @return array<class-string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            RoleAttachedEvent::class => 'onRoleAttached',
            RoleDetachedEvent::class => 'onRoleDetached',
        ];
    }
}
