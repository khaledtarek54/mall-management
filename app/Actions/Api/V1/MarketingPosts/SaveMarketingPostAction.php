<?php

namespace App\Actions\Api\V1\MarketingPosts;

use App\Models\MarketingPost;
use App\Models\Tenant;
use App\Services\MarketingPost\SubmitMarketingPostService;
use DomainException;
use Illuminate\Http\UploadedFile;

/**
 * Create or edit a retailer's own marketing post from the mobile API.
 *
 * Everything a client sends is treated as untrusted:
 *
 *  - **`tenant_id` is never read from the request.** It is the authenticated tenant, full stop.
 *    Accepting it would let any retailer publish under a competitor's name.
 *  - **`asset_id` is re-checked against real leases**, via the same guard the submit transition
 *    uses — the form request's `exists` rule proves the mall is a mall, not that this retailer
 *    trades in it.
 *  - **`status` is never taken from input.** A new post is a draft; an edit leaves the status
 *    alone. The only way to `pending` is the submit endpoint, and the only way to `published` is
 *    an operator approving it.
 *
 * An edit is refused unless the post is still the retailer's to change (`draft` / `rejected`).
 * Without that, a retailer could swap the artwork of an already-approved offer for something the
 * mall never reviewed — approval would be meaningless.
 */
class SaveMarketingPostAction
{
    public function __construct(private readonly SubmitMarketingPostService $submitter) {}

    /**
     * @param  array<string,mixed>  $data  From SaveMarketingPostRequest::payload()
     * @param  array{hero?: ?UploadedFile, gallery?: array<int, UploadedFile>}  $files
     */
    public function create(Tenant $tenant, array $data, array $files = []): MarketingPost
    {
        $assetId = (int) ($data['asset_id'] ?? 0);
        $this->submitter->assertTenantTradesIn($tenant->getKey(), $assetId);

        $post = new MarketingPost($this->attributes($data));
        $post->tenant_id = $tenant->getKey();
        $post->asset_id = $assetId;
        // Left NULL on purpose — it is what marks the post tenant-authored, so the review verdict
        // is sent back to the retailer. See MarketingPost::isTenantAuthored().
        $post->created_by = null;
        $post->status = MarketingPost::STATUS_DRAFT;
        $post->save();

        $this->attachMedia($post, $files);

        return $post->refresh();
    }

    /**
     * @param  array<string,mixed>  $data
     * @param  array{hero?: ?UploadedFile, gallery?: array<int, UploadedFile>}  $files
     */
    public function update(MarketingPost $post, Tenant $tenant, array $data, array $files = []): MarketingPost
    {
        if ($post->tenant_id !== $tenant->getKey()) {
            // Never 403 — a cross-tenant record must be indistinguishable from one that does not
            // exist (the /api/v1 no-enumeration rule). The controller resolves through the
            // tenant's own scope, so this is the backstop rather than the gate.
            abort(404);
        }

        if (! $post->isEditableByTenant()) {
            throw new DomainException(__('api.marketing_post_not_editable'));
        }

        if (array_key_exists('asset_id', $data)) {
            $this->submitter->assertTenantTradesIn($tenant->getKey(), (int) $data['asset_id']);
        }

        $post->fill($this->attributes($data));
        $post->save();

        $this->attachMedia($post, $files);

        return $post->refresh();
    }

    /**
     * Strip anything a retailer must not set, whatever the request contained.
     *
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function attributes(array $data): array
    {
        unset($data['status'], $data['is_featured'], $data['priority'], $data['tenant_id'],
            $data['created_by'], $data['reviewed_by'], $data['reviewed_at'], $data['review_notes'],
            $data['published_at'], $data['view_count'], $data['click_count'],
            $data['display_from'], $data['display_until']);

        return $data;
    }

    /**
     * @param  array{hero?: ?UploadedFile, gallery?: array<int, UploadedFile>}  $files
     */
    private function attachMedia(MarketingPost $post, array $files): void
    {
        // After the row saves and outside any transaction — media moves files on disk. Mirrors
        // CreateSalesDeclarationAction / CreateTenantRequestAction.
        if (($hero = $files['hero'] ?? null) instanceof UploadedFile) {
            // singleFile() collection: a re-upload replaces rather than accumulating.
            $post->addMedia($hero)->toMediaCollection(MarketingPost::HERO_COLLECTION);
        }

        foreach ($files['gallery'] ?? [] as $image) {
            if ($image instanceof UploadedFile) {
                $post->addMedia($image)->toMediaCollection(MarketingPost::GALLERY_COLLECTION);
            }
        }
    }
}
