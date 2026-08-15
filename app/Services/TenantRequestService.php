<?php

namespace App\Services;

use App\Enums\TenantRequestType;
use App\Models\Department;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Models\TenantRequestComment;
use App\Models\User;
use App\Notifications\TenantRequestCommentAddedNotification;
use App\Notifications\TenantRequestStatusChangedNotification;
use App\Notifications\PortalRequestSubmittedNotification;
use App\Settings\SlaSettings;
use Carbon\Carbon;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class TenantRequestService
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

    public function create(array $data, Tenant $tenant): TenantRequest
    {
        return DB::transaction(function () use ($data, $tenant) {
            // Clamp the client-supplied unit_id to the tenant's OWN lease. The portal form's options()
            // scope only the rendering — a crafted Livewire submit can post another retailer's unit_id,
            // and (unlike the mobile API's CreateTenantRequestRequest, which has an exists-rule) the
            // portal form has no server-side rule. Without this a portal user could file a request
            // against another tenant's unit → their unit code leaks back through the request view, and
            // the request misroutes to that unit's PROPERTY staff / area supervisors. Deriving the
            // lease from the tenant's own row (never the client) keeps unit_id + lease_id consistent
            // and tenant-owned. Guard here, the single choke point for the portal + API create paths.
            //
            // Resolve through the lease_unit PIVOT, not `leases.unit_id`. A multi-unit lease keeps
            // its additional units in the pivot and only the MASTER in the column, so a
            // column-only lookup made a tenant's own extra units unreachable: the request either
            // 422'd (mobile) or silently fell through to `activeLeases()->first()` and was filed
            // against the WRONG unit (portal) — sending the crew to the wrong shop and routing to
            // the wrong area supervisors. Real case: Cilantro leases A-01 + C-09 and could only
            // report faults for A-01. Matching the column too is belt-and-braces for any lease
            // whose master was never synced into the pivot.
            $requestedUnitId = isset($data['unit_id']) ? (int) $data['unit_id'] : null;
            /** @var \App\Models\Lease|null $lease — the `?? activeLeases()->first()` fallback otherwise widens it to Model, hiding units()/unit. */
            $lease = ($requestedUnitId !== null
                ? $tenant->leases()
                    ->where(fn ($q) => $q
                        ->where('unit_id', $requestedUnitId)
                        ->orWhereHas('units', fn ($u) => $u->whereKey($requestedUnitId)))
                    ->first()
                : null)
                ?? $tenant->activeLeases()->first();

            // The unit the tenant actually ASKED about, when it belongs to the resolved lease —
            // not that lease's master, which is what made a fault in the second shop arrive
            // labelled as the first. Falls back to the master when the request named nothing, or
            // named a unit that is not on this lease (i.e. someone else's — the clamp still holds).
            /** @var \App\Models\Unit|null $unit — units() (BelongsToMany) ->first() resolves to base Model, so narrow. */
            $unit = $requestedUnitId !== null
                ? ($lease?->units()->whereKey($requestedUnitId)->first() ?? $lease?->unit)
                : $lease?->unit;

            $type = isset($data['request_type'])
                ? TenantRequestType::from($data['request_type'])
                : TenantRequestType::default();

            $priority = $data['priority'] ?? 'medium';

            $request = TenantRequest::create([
                'reference' => TenantRequest::generateReference(
                    $unit?->asset?->code ?? 'AW',
                    $type->referencePrefix(),
                ),
                'tenant_id' => $tenant->id,
                // From the clamped lease only — never the raw client unit_id/lease_id (see above).
                'unit_id' => $unit?->id,
                'lease_id' => $lease?->id,
                'request_type' => $type->value,
                'status' => 'submitted',
                'priority' => $priority,
                // `category` holds the type's sub-category (electrical, parking,
                // lease_copy, …). Forced null for types that have none (inquiry,
                // billing) so a stray cross-type value can never persist.
                'category' => $type->subcategories() === [] ? null : ($data['category'] ?? null),
                'department_id' => $data['department_id'] ?? $this->defaultDepartmentIdFor($type),
                'title' => $data['title'],
                'description' => $data['description'],
                'submitted_at' => now(),
                'target_resolution_at' => $this->targetResolutionFor($type, $priority),
            ]);

            $this->notifyOperators($request);

            return $request;
        });
    }

    /**
     * The department a request type is auto-routed to on intake (by Department
     * slug — see TenantRequestType::defaultDepartmentSlug). Null when the type
     * has no default team, or the department isn't seeded — it then lands
     * unassigned for manual triage (existing maintenance behaviour).
     */
    private function defaultDepartmentIdFor(TenantRequestType $type): ?int
    {
        $slug = $type->defaultDepartmentSlug();

        if ($slug === null) {
            return null;
        }

        return Department::query()->where('slug', $slug)->value('id');
    }

    /**
     * SLA target for a request. Types without an SLA (inquiry, billing query,
     * document request) get no deadline. Maintenance keeps reading the operator-
     * tunable SlaSettings; other typed SLAs use the per-type code map.
     *
     * Public so the admin create page shares this exact logic — otherwise a
     * Complaint/Access request created in /admin would wrongly get the
     * maintenance window instead of its own.
     */
    public function targetResolutionFor(TenantRequestType $type, string $priority): ?Carbon
    {
        if (! $type->hasSla()) {
            return null;
        }

        if ($type === TenantRequestType::Maintenance) {
            return $this->defaultTargetResolution($priority);
        }

        $hours = $type->slaHours()[$priority] ?? null;

        return $hours === null ? null : now()->addHours((int) $hours);
    }

    /**
     * Notify managers + operationss that a new portal-submitted
     * request needs triage. Database channel only — bell entry, no email.
     * Scopes to staff actually assigned to the unit's asset so multi-property
     * deployments don't fan out cross-property.
     *
     * The whole block is wrapped in Throwable — if the Spatie roles aren't
     * seeded (e.g. minimal test envs), the role() query throws RoleDoesNotExist
     * and we silently skip rather than breaking the request creation path.
     */
    private function notifyOperators(TenantRequest $request): void
    {
        try {
            $recipients = $this->staffRecipientsFor($request);

            if ($recipients->isNotEmpty()) {
                Notification::send(
                    $recipients,
                    new PortalRequestSubmittedNotification($request)
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('Portal maintenance notification fan-out failed', [
                'request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Property-team recipients for a request: managers / operationss
     * assigned to the unit's asset, plus every super_admin (platform owners see
     * all property activity). Shared by the submit fan-out and tenant-comment
     * fan-out so both target the same people.
     *
     * @return Collection<int, User>
     */
    private function staffRecipientsFor(TenantRequest $request): Collection
    {
        return app(AssetStaffRecipients::class)->for(
            $request->unit?->asset_id,
            ['manager', 'operations'],
        );
    }

    /**
     * The staff User making this change, or null.
     *
     * Deliberately narrower than `auth()->id()`: it checks the resolved user is actually a
     * {@see User}. A `/portal` or `/api/v1` caller is a TenantUser or a Tenant, and writing either
     * of their ids into `tenant_requests.decided_by` — a FK to `users` — would attribute the
     * mall's decision to whoever it happened to number-match.
     */
    protected static function actingUserId(): ?int
    {
        $user = auth()->user();

        return $user instanceof User ? $user->getKey() : null;
    }

    public function transition(TenantRequest $request, string $next, array $extra = []): TenantRequest
    {
        $current = $request->status;

        if (! in_array($next, self::TRANSITIONS[$current] ?? [], true)) {
            throw new InvalidArgumentException(
                "Illegal transition: {$current} → {$next}"
            );
        }

        // FR-USR-06 — evidence before you can call a tenant's problem fixed.
        //
        // "The system shall require evidence (an uploaded image OR a linked work order) before a
        // request ... can be marked complete." Both are proof the work happened: a photo of the
        // fix, or the facility work order raised to do it (the module 11 → 26 link). This is the
        // single gate for admin + portal + API, since every channel resolves through here — a rule
        // enforced in the UI only is a rule the mobile client skips.
        //
        // `resolved`, not `closed`: resolving is the act of saying "done"; closing is the
        // administrative follow-up, and a resolved request already cleared this.
        if ($next === 'resolved'
            && ! $request->hasLinkedWorkOrder()
            && ! $request->hasMedia('attachments')) {
            throw new DomainException(__('admin.tenant_requests.errors.resolution_needs_evidence'));
        }

        // **A request that ASKED for something cannot be finished without an answer.**
        //
        // Permits, access and documents are questions (see TenantRequestType::requiresDecision).
        // Resolving one without saying yes or no leaves the outcome to be guessed from the
        // lifecycle — which is exactly what the mobile permit card did, mapping `resolved`/`closed`
        // to "Approved" and so showing a REFUSED tenant an approval on the artifact they hand a
        // security guard.
        //
        // Guarded here rather than in a form, because this is the one place admin, portal and the
        // mobile API all pass through; a rule enforced in the UI is a rule the API skips.
        // A rejection must also carry its reason — a tenant told only "rejected" resubmits the
        // same request on Monday, which costs the operator the time the refusal was meant to save.
        $decision = $extra['decision'] ?? null;

        if ($next === 'resolved' && $request->requiresDecision() && $decision === null) {
            throw new DomainException(__('admin.tenant_requests.errors.decision_required'));
        }

        if ($decision !== null && ! in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException("Unknown decision: {$decision}");
        }

        if ($decision === 'rejected' && blank($extra['decision_reason'] ?? null)) {
            throw new DomainException(__('admin.tenant_requests.errors.rejection_needs_reason'));
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

        // Stamped whenever an answer is given, which in practice is on the `resolved` hop. Kept
        // outside the match so a later correction (resolved → in_progress → resolved) can restate
        // it, and so `decided_by` records WHO answered — a refusal someone put their name to.
        if ($decision !== null) {
            $payload['decision'] = $decision;
            $payload['decision_reason'] = $extra['decision_reason'] ?? null;
            $payload['decided_at'] = now();
            // **Only a staff User, resolved explicitly.** `decided_by` is a FK to `users`, and
            // `auth()->id()` answers for the DEFAULT guard — which is not the guard an /api/v1
            // caller authenticated on. Today no tenant-reachable transition passes a decision (the
            // API's only hop is `cancelled`, with no extra), so this is hardening rather than a
            // live fix; but relying on "the default guard happens to be web, so a Tenant resolves
            // to null" is the kind of implicit thing that stops being true quietly.
            $payload['decided_by'] = $extra['decided_by'] ?? self::actingUserId();
        }

        if (array_key_exists('assigned_to', $extra)) {
            $payload['assigned_to'] = $extra['assigned_to'];
        }

        $request->update($payload);

        // Notify the requesting tenant. Skip the cancelled-by-tenant case
        // because the tenant just triggered it themselves (their own
        // cancellation doesn't need a self-notification).
        if ($next !== 'cancelled' && $request->tenant) {
            try {
                $request->tenant->notifyPortal(
                    new TenantRequestStatusChangedNotification($request->refresh(), $current)
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

    public function assign(TenantRequest $request, ?int $userId): TenantRequest
    {
        if ($request->isTerminal()) {
            return $request;
        }

        $request->update(['assigned_to' => $userId]);

        if ($userId && $request->status === 'submitted') {
            return $this->transition($request, 'acknowledged');
        }

        return $request->refresh();
    }

    /** Statuses at which the tenant may submit a close-out satisfaction rating. */
    public const RATEABLE = ['resolved', 'closed'];

    /**
     * Record the tenant's close-out satisfaction (CSAT 1–5 + optional comment).
     * Only a resolved/closed request can be rated; the tenant may overwrite an
     * earlier rating. Single source of truth for both the portal action and the
     * mobile API, so the rateable rule can't drift between them.
     */
    public function rate(TenantRequest $request, int $rating, ?string $comment = null): TenantRequest
    {
        if (! in_array($request->status, self::RATEABLE, true)) {
            throw ValidationException::withMessages([
                'status' => [__('api.request_cannot_rate')],
            ]);
        }

        $comment = $comment !== null && trim($comment) !== '' ? trim($comment) : null;

        $request->update([
            'csat_rating' => max(1, min(5, $rating)),
            'csat_comment' => $comment,
        ]);

        return $request->refresh();
    }

    /**
     * Route (or re-route) a work-order to an operator department. Passing null
     * clears the assignment. Redirecting a mis-triaged request to the correct
     * department is just an update of this column (FR MNT-2 / MNT-3); the
     * activity log captures the from→to change.
     */
    public function redirectToDepartment(TenantRequest $request, ?int $departmentId): TenantRequest
    {
        // Terminal (closed/cancelled) work-orders are immutable (FR REQ-3) — the
        // UI hides the action, but guard the service too so no path can re-route
        // a finished request.
        if ($request->isTerminal()) {
            return $request;
        }

        $request->update(['department_id' => $departmentId]);

        return $request->refresh();
    }

    public function comment(TenantRequest $request, Model $author, string $body, bool $isInternal = false): TenantRequestComment
    {
        // Terminal (closed/cancelled) requests are immutable — including their
        // comment thread. Resolved is NOT terminal, so a tenant can still reply
        // to (re-open) a resolved request. Single guard for admin + portal + API.
        if ($request->isTerminal()) {
            throw ValidationException::withMessages([
                'body' => [__('api.request_cannot_comment')],
            ]);
        }

        $comment = TenantRequestComment::create([
            'tenant_request_id' => $request->id,
            'author_type' => $author->getMorphClass(),
            'author_id' => $author->getKey(),
            'body' => $body,
            'is_internal' => $isInternal,
        ]);

        // Internal staff-only notes are never broadcast. Public comments fan
        // out: a tenant's comment pings the property team (the gap QA hit),
        // and a staff comment pings the tenant — keeping both sides in sync.
        if (! $isInternal) {
            $this->notifyOfComment($request, $author, $comment);
        }

        return $comment;
    }

    /**
     * Route a public comment to the other party. Wrapped in Throwable so a
     * missing role catalogue / mail hiccup never breaks the comment write.
     */
    private function notifyOfComment(TenantRequest $request, Model $author, TenantRequestComment $comment): void
    {
        try {
            if ($author instanceof Tenant) {
                $recipients = $this->staffRecipientsFor($request);
                if ($recipients->isNotEmpty()) {
                    Notification::send(
                        $recipients,
                        new TenantRequestCommentAddedNotification($request, $comment)
                    );
                }

                return;
            }

            // Staff (or system) author → notify the requesting tenant.
            if ($request->tenant) {
                $request->tenant->notifyPortal(
                    new TenantRequestCommentAddedNotification($request, $comment)
                );
            }
        } catch (\Throwable $e) {
            \Log::warning('Maintenance comment notification failed', [
                'request_id' => $request->id,
                'comment_id' => $comment->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * SLA target for a request based on its priority. Reads from the
     * SlaSettings (operator-tunable via /admin/settings → Service levels)
     * first, then falls back to config/sla.php so a deploy without
     * Settings rows still produces a sensible target (audit M09 F-36 / D-28).
     */
    public function defaultTargetResolution(string $priority): Carbon
    {
        try {
            $settings = app(SlaSettings::class);
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

        $hours ??= config("sla.{$priority}.resolve_hours", 168);

        return now()->addHours((int) $hours);
    }
}
