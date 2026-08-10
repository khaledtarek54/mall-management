<?php

namespace App\Services\MarketingPost;

use App\Models\MarketingPost;
use App\Models\Tenant;
use App\Models\TenantUser;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * A retailer sends their own offer to the mall's marketing team for review.
 *
 * This is the entry point for the tenant-authored half of the module (portal + mobile API), and
 * the place the two things a retailer must not be able to do are stopped:
 *
 *  1. **Publish without review.** The transition ends at `pending`, never `published`. Nothing a
 *     tenant can call reaches the public feed.
 *  2. **Post into a mall they do not trade in.** `assertTenantTradesIn()` is the property-isolation
 *     WRITE guard for this module's non-Filament surfaces. The admin panel gets this from
 *     `GuardsAssetInScope`; the portal and the API have no such trait, and a client-supplied
 *     `asset_id` is exactly the tampering the isolation invariant exists to stop.
 */
class SubmitMarketingPostService
{
    public function handle(MarketingPost $post, ?TenantUser $submitter = null): MarketingPost
    {
        return DB::transaction(function () use ($post, $submitter) {
            /** @var MarketingPost $fresh */
            $fresh = MarketingPost::query()->lockForUpdate()->findOrFail($post->getKey());

            if ($fresh->status === MarketingPost::STATUS_PENDING) {
                return $fresh; // Already queued — a double-tap on a slow connection.
            }

            if (! $fresh->isEditableByTenant()) {
                // Published / archived: the operator owns it now. A retailer who wants a live
                // offer changed asks, rather than silently pulling it back into review.
                throw new DomainException(__('admin.errors.marketing_post_not_submittable'));
            }

            if (blank($fresh->title)) {
                throw new DomainException(__('admin.errors.marketing_post_needs_title'));
            }

            if ($fresh->tenant_id === null) {
                // A mall-wide post has no retailer to submit it; only the operator writes those.
                throw new DomainException(__('admin.errors.marketing_post_no_store'));
            }

            $this->assertTenantTradesIn($fresh->tenant_id, $fresh->asset_id);

            $fresh->status = MarketingPost::STATUS_PENDING;
            $fresh->submitted_by_tenant_user_id = $submitter?->getKey() ?? $fresh->submitted_by_tenant_user_id;
            // Clear the previous round's verdict — this is a new submission, and leaving the old
            // rejection reason attached makes the queue read as though it was already refused.
            $fresh->review_notes = null;
            $fresh->reviewed_by = null;
            $fresh->reviewed_at = null;
            $fresh->save();

            return $fresh;
        });
    }

    /**
     * Refuse a post aimed at a property where this tenant holds no active lease.
     *
     * Reached through `activeLeases.units` (the `lease_unit` pivot) rather than `leases.unit_id` —
     * the latter is only the MASTER unit, so a retailer whose presence in this mall is an
     * ADDITIONAL unit on a multi-unit lease would be wrongly refused. Same trap the announcement
     * fan-out documents.
     */
    public function assertTenantTradesIn(int $tenantId, int $assetId): void
    {
        $trades = Tenant::query()
            ->whereKey($tenantId)
            ->whereHas('activeLeases.units', fn ($q) => $q->where('units.asset_id', $assetId))
            ->exists();

        if (! $trades) {
            throw new DomainException(__('admin.errors.marketing_post_wrong_property'));
        }
    }

    /** Pull a queued post back to draft. The retailer's own undo, before anyone reviewed it. */
    public function withdraw(MarketingPost $post): MarketingPost
    {
        return DB::transaction(function () use ($post) {
            /** @var MarketingPost $fresh */
            $fresh = MarketingPost::query()->lockForUpdate()->findOrFail($post->getKey());

            if ($fresh->status !== MarketingPost::STATUS_PENDING) {
                throw new DomainException(__('admin.errors.marketing_post_not_withdrawable'));
            }

            $fresh->status = MarketingPost::STATUS_DRAFT;
            $fresh->save();

            return $fresh;
        });
    }
}
