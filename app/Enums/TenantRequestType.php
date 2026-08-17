<?php

namespace App\Enums;

use App\Services\TenantRequestService;

/**
 * The kinds of request a tenant can raise. "Maintenance" is the original — the
 * others generalise the feature so a tenant can request anything (see
 * docs/plans/01-tenant-requests-plan.md). Model-level, not a DB enum:
 * tenant_requests.request_type is a string, so adding a type needs no migration.
 *
 * Each case carries its own intake config (sub-categories, SLA, routing,
 * scheduling, reference prefix) — the per-type behaviour mature property-
 * management systems expose. Phase 2 promotes this to an operator-tunable
 * `request_types` table; the columns mirror these accessors so it's a drop-in.
 *
 * Labels map to admin.enums.tenant_request_type.* ; sub-categories to
 * admin.enums.tenant_request_subcategory.* .
 */
enum TenantRequestType: string
{
    case Maintenance = 'maintenance';
    case Complaint = 'complaint';
    case Inquiry = 'inquiry';
    case Access = 'access';
    case Billing = 'billing';
    case Document = 'document';
    case Permit = 'permit';
    case Other = 'other';

    /** The type a row falls back to (every legacy maintenance request is this). */
    public static function default(): self
    {
        return self::Maintenance;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Localized value => label map for Filament selects + table formatting. */
    public static function options(): array
    {
        return collect(self::cases())
            ->filter(fn (self $c) => $c->isActive())
            ->mapWithKeys(fn (self $c) => [$c->value => $c->label()])
            ->all();
    }

    public function label(): string
    {
        return __("admin.enums.tenant_request_type.{$this->value}");
    }

    /**
     * Whether tenants may raise this type themselves. All are active today; the
     * accessor exists so a type can be retired without removing the case (old
     * rows still resolve).
     */
    public function isActive(): bool
    {
        return true;
    }

    /**
     * Optional sub-categories shown once this type is picked. Empty = the type
     * needs no sub-category. Keys map to admin.enums.tenant_request_subcategory.* .
     *
     * @return list<string>
     */
    public function subcategories(): array
    {
        return match ($this) {
            self::Maintenance => ['electrical', 'plumbing', 'hvac', 'structural', 'cleaning', 'safety', 'other'],
            self::Access => ['keys_cards', 'parking', 'after_hours', 'visitor', 'delivery'],
            self::Document => ['lease_copy', 'renewal', 'termination_notice', 'noc_certificate'],
            self::Permit => ['fit_out', 'temporary_installation', 'signage', 'other'],
            self::Complaint => ['noise', 'cleanliness', 'conduct', 'other'],
            default => [],
        };
    }

    /**
     * Whether resolving this type has to state an ANSWER — approved or rejected.
     *
     * The three here are the ones where the tenant is asking for permission or for a thing: a
     * permit to work, access to somewhere, a document. For those, "resolved" on its own is not an
     * outcome, and a client rendering the request has nothing to show but the lifecycle — which is
     * how a staff rejection came to read as an approval on the mobile permit card.
     *
     * The rest are not questions. A leaking pipe is fixed or it is not; a complaint is addressed;
     * an enquiry is answered. Forcing an approve/reject on those would make the operator pick a
     * word that does not describe what they did.
     *
     * @see TenantRequestService::transition()  refuses to resolve one of these blind
     */
    public function requiresDecision(): bool
    {
        return match ($this) {
            self::Permit, self::Access, self::Document => true,
            default => false,
        };
    }

    /** Whether this type is governed by a resolution SLA (drives target_resolution_at). */
    public function hasSla(): bool
    {
        return match ($this) {
            self::Maintenance, self::Complaint, self::Access => true,
            default => false,
        };
    }

    /**
     * Per-priority SLA window in hours. Sensible code defaults; Phase 2 reads
     * these from settings/the request_types table. Empty when !hasSla().
     *
     * @return array<string,int>
     */
    public function slaHours(): array
    {
        if (! $this->hasSla()) {
            return [];
        }

        return match ($this) {
            self::Maintenance => ['urgent' => 4, 'high' => 24, 'medium' => 72, 'low' => 168],
            self::Complaint => ['urgent' => 8, 'high' => 48, 'medium' => 96, 'low' => 168],
            self::Access => ['urgent' => 4, 'high' => 24, 'medium' => 48, 'low' => 96],
            default => [],
        };
    }

    /** Whether intake captures a scheduled work/visit window (scheduled_from/to). */
    public function allowsScheduling(): bool
    {
        return match ($this) {
            self::Maintenance, self::Access => true,
            default => false,
        };
    }

    /**
     * The team this type is routed to by default, by Department slug. Null =
     * lands unassigned for manual triage. Wired into auto-routing in Phase 2.
     */
    public function defaultDepartmentSlug(): ?string
    {
        return match ($this) {
            self::Maintenance, self::Access, self::Permit => 'operations',
            self::Billing => 'accounting',
            self::Document => 'leasing',
            default => null,
        };
    }

    /** Reference prefix, e.g. MR-AW-2026-0001. */
    public function referencePrefix(): string
    {
        return match ($this) {
            self::Maintenance => 'MR',
            self::Complaint => 'CR',
            self::Inquiry => 'IQ',
            self::Access => 'AR',
            self::Billing => 'BQ',
            self::Document => 'DR',
            self::Permit => 'PM',
            self::Other => 'REQ',
        };
    }
}
