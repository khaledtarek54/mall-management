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

            return $request;
        });
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

    public function defaultTargetResolution(string $priority): \Carbon\Carbon
    {
        $hours = config("maintenance.sla.{$priority}.resolve_hours", 168);

        return now()->addHours($hours);
    }
}
