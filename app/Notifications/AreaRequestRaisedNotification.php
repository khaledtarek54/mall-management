<?php

namespace App\Notifications;

use App\Models\TenantRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Supervisor-side bell entry when a request lands in a facility zone the user oversees
 * (module 30 → 11 routing). Routes to the area's supervisors (Area::supervisors).
 *
 * Database + push, no mail — matches MaintenanceSlaBreachedNotification's channel choice: this is a
 * high-frequency operations signal, the bell (and the app) is the right surface, not the inbox.
 * Push is a no-op for admin Users (they register no device tokens) but is declared so the routing
 * reaches the mobile app the moment a supervisor is a push-capable notifiable.
 */
class AreaRequestRaisedNotification extends Notification
{
    use Queueable;

    public function __construct(public TenantRequest $request) {}

    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'area_request_raised',
            'request_id' => $this->request->id,
            'reference' => $this->request->reference,
            'area_id' => $this->request->area_id,
            'area' => $this->request->areaName(),
            'unit' => $this->request->unitCode(),
            'priority' => $this->request->priority,
            'title' => __('admin.notifications.area_request_raised_title', [
                'area' => $this->request->areaName() ?? '—',
            ]),
            'body' => __('admin.notifications.area_request_raised_body', [
                'type' => $this->request->typeLabel(),
                'title' => $this->request->title,
                'unit' => $this->request->unitCode() ?? '—',
                'priority' => __("admin.enums.maintenance_priority.{$this->request->priority}"),
            ]),
            'icon' => 'heroicon-o-map-pin',
            'color' => $this->request->priority === 'urgent' ? 'danger' : 'warning',
            'url' => null,
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
