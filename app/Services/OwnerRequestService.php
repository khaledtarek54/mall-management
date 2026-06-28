<?php

namespace App\Services;

use App\Models\OwnerRequest;
use App\Models\User;
use App\Notifications\OwnerRequestNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * Creates and progresses owner (Jawad) requests (FR OWN-1/2): raise to the
 * operator team or to another owner, and route bell notifications both ways.
 */
class OwnerRequestService
{
    public function create(array $data, User $owner): OwnerRequest
    {
        return DB::transaction(function () use ($data, $owner) {
            $request = OwnerRequest::create([
                'reference' => OwnerRequest::generateReference(),
                'created_by_user_id' => $owner->id,
                'asset_id' => $data['asset_id'] ?? null,
                'recipient' => $data['recipient'] ?? 'operator',
                'assigned_to_user_id' => $data['assigned_to_user_id'] ?? null,
                'subject' => $data['subject'],
                'body' => $data['body'],
                'priority' => $data['priority'] ?? 'medium',
                'status' => 'open',
                'scheduled_from' => $data['scheduled_from'] ?? null,
                'scheduled_to' => $data['scheduled_to'] ?? null,
            ]);

            $this->notifyRecipients($request);

            return $request;
        });
    }

    /**
     * Operator (or owner) progresses the request. Notifies the owner who
     * raised it. Terminal requests are immutable — guarded at the UI layer.
     */
    public function transition(OwnerRequest $request, string $status, array $extra = []): OwnerRequest
    {
        // Terminal requests (resolved / closed / cancelled) are immutable — the
        // UI hides the respond action, but guard at the service layer too so no
        // caller can mutate an already-responded request.
        if ($request->isTerminal()) {
            return $request;
        }

        $payload = ['status' => $status];

        if ($status === 'resolved') {
            $payload['resolved_at'] = now();
            $payload['resolution_notes'] = $extra['resolution_notes'] ?? $request->resolution_notes;
        }
        if ($status === 'closed') {
            $payload['closed_at'] = now();
        }

        $request->update($payload);

        try {
            $request->creator?->notify(new OwnerRequestNotification($request->refresh(), 'updated'));
        } catch (\Throwable $e) {
            Log::warning('Owner request update notification failed', [
                'owner_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }

        return $request->refresh();
    }

    private function notifyRecipients(OwnerRequest $request): void
    {
        try {
            if ($request->recipient === 'owner' && $request->assignee) {
                $request->assignee->notify(new OwnerRequestNotification($request, 'submitted'));

                return;
            }

            // To the operator team: super_admin + manager users.
            $recipients = User::query()
                ->whereHas('roles', fn ($q) => $q->whereIn('name', ['super_admin', 'manager']))
                ->get();

            if ($recipients->isNotEmpty()) {
                Notification::send($recipients, new OwnerRequestNotification($request, 'submitted'));
            }
        } catch (\Throwable $e) {
            Log::warning('Owner request submit notification failed', [
                'owner_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
