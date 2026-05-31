<?php

namespace App\Services;

use App\Models\MaintenanceRequest;
use App\Models\MaintenanceRequestComment;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class MaintenanceRequestService
{
    /**
     * Legal transitions. Keys = current status; values = allowed next statuses.
     *
     * Tenants can: create (→ submitted), reply when awaiting_tenant (→ in_progress),
     * cancel from submitted/acknowledged. Staff drive the rest.
     */
    public const TRANSITIONS = [
        'submitted' => ['acknowledged', 'in_progress', 'cancelled'],
        'acknowledged' => ['in_progress', 'awaiting_tenant', 'cancelled'],
        'in_progress' => ['awaiting_tenant', 'resolved', 'cancelled'],
        'awaiting_tenant' => ['in_progress', 'resolved', 'cancelled'],
        'resolved' => ['closed', 'in_progress'],
        'closed' => [],
        'cancelled' => [],
    ];

    public function create(array $data, Tenant $tenant): MaintenanceRequest
    {
        return DB::transaction(function () use ($data, $tenant) {
            $unit = $tenant->activeLeases()->first()?->unit;
            $lease = $tenant->activeLeases()->first();

            $priority = $data['priority'] ?? 'medium';

            $request = MaintenanceRequest::create([
                'reference' => MaintenanceRequest::generateReference(
                    $unit?->asset?->code ?? 'HW'
                ),
                'tenant_id' => $tenant->id,
                'unit_id' => $data['unit_id'] ?? $unit?->id,
                'lease_id' => $data['lease_id'] ?? $lease?->id,
                'status' => 'submitted',
                'priority' => $priority,
                'category' => $data['category'] ?? 'other',
                'title' => $data['title'],
                'description' => $data['description'],
                'submitted_at' => now(),
                'target_resolution_at' => $this->defaultTargetResolution($priority),
            ]);

            $this->notifyOperators($request);

            return $request;
        });
    }

    /**
     * Notify managers + maintenance_managers that a new portal-submitted
     * request needs triage. Database channel only — bell entry, no email.
     * Scopes to staff actually assigned to the unit's asset so multi-property
     * deployments don't fan out cross-property.
     *
     * The whole block is wrapped in Throwable — if the Spatie roles aren't
     * seeded (e.g. minimal test envs), the role() query throws RoleDoesNotExist
     * and we silently skip rather than breaking the request creation path.
     */
    private function notifyOperators(MaintenanceRequest $request): void
    {
        $assetId = $request->unit?->asset_id;
        if (! $assetId) {
            return;
        }

        try {
            $recipients = \App\Models\User::query()
                ->role(['manager', 'maintenance_manager', 'super_admin'])
                ->whereHas('assignedAssets', fn ($q) => $q->where('assets.id', $assetId))
                ->get();

            if ($recipients->isEmpty()) {
                $recipients = \App\Models\User::query()->role('super_admin')->get();
            }

            if ($recipients->isNotEmpty()) {
                \Illuminate\Support\Facades\Notification::send(
                    $recipients,
                    new \App\Notifications\PortalMaintenanceSubmittedNotification($request)
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('Portal maintenance notification fan-out failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function transition(MaintenanceRequest $request, string $next, array $extra = []): MaintenanceRequest
    {
        $current = $request->status;

        if (! in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
            throw new InvalidArgumentException(
                "Illegal transition: {$current} → {$next}"
            );
        }

        $payload = ['status' => $next];

        match ($next) {
            'acknowledged' => $payload['acknowledged_at'] = now(),
            'resolved' => $payload = array_merge($payload, [
                'resolved_at' => now(),
                'resolution_notes' => $extra['resolution_notes'] ?? $request->resolution_notes,
            ]),
            'closed' => $payload['closed_at'] = now(),
            default => null,
        };

        if (array_key_exists('assigned_to', $extra)) {
            $payload['assigned_to'] = $extra['assigned_to'];
        }

        $request->update($payload);

        // Notify the requesting tenant. Skip the cancelled-by-tenant case
        // because the tenant just triggered it themselves (their own
        // cancellation doesn't need a self-notification).
        if ($next !== 'cancelled' && $request->tenant) {
            try {
                $request->tenant->notify(
                    new \App\Notifications\MaintenanceStatusChangedNotification($request->refresh(), $current)
                );
            } catch (\Throwable $e) {
                \Log::warning('Maintenance status notification failed', [
                    'request_id' => $request->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $request->refresh();
    }

    public function assign(MaintenanceRequest $request, ?int $userId): MaintenanceRequest
    {
        $request->update(['assigned_to' => $userId]);

        if ($userId && $request->status === 'submitted') {
            return $this->transition($request, 'acknowledged');
        }

        return $request->refresh();
    }

    public function comment(MaintenanceRequest $request, Model $author, string $body, bool $isInternal = false): MaintenanceRequestComment
    {
        return MaintenanceRequestComment::create([
            'maintenance_request_id' => $request->id,
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
            'body' => $body,
            'is_internal' => $isInternal,
        ]);
    }

    /**
     * SLA target for a request based on its priority. Reads from the
     * MaintenanceSettings (operator-tunable via /admin/settings → Maintenance)
     * first, then falls back to config/maintenance.php so a deploy without
     * Settings rows still produces a sensible target (audit M09 F-36 / D-28).
     */
    public function defaultTargetResolution(string $priority): \Carbon\Carbon
    {
        try {
            $settings = app(\App\Settings\MaintenanceSettings::class);
            $hours = match ($priority) {
                'urgent' => $settings->sla_urgent_hours,
                'high' => $settings->sla_high_hours,
                'medium' => $settings->sla_medium_hours,
                'low' => $settings->sla_low_hours,
                default => null,
            };
        } catch (\Throwable $e) {
            $hours = null;
        }

        $hours ??= config("maintenance.sla.{$priority}.resolve_hours", 168);

        return now()->addHours((int) $hours);
    }
}
