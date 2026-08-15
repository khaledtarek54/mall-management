<?php

namespace App\Http\Controllers\Api\V1\MarketingPosts;

use App\Actions\Api\V1\MarketingPosts\SaveMarketingPostAction;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\MarketingPosts\SaveMarketingPostRequest;
use App\Http\Resources\Api\V1\MarketingPostResource;
use App\Http\Resources\Api\V1\PublicFeed\PublicMarketingPostResource;
use App\Models\MarketingPost;
use App\Models\Tenant;
use App\Services\MarketingPost\SubmitMarketingPostService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The retailer's own marketing posts, over `/api/v1/me/marketing-posts`.
 *
 * Grouped in one controller rather than split into eight single-action classes because these are
 * thin CRUD shells over {@see SaveMarketingPostAction} and
 * {@see SubmitMarketingPostService} — the business logic lives there, and eight files of
 * four lines each would obscure rather than clarify. The project's single-action rule is about
 * where the LOGIC lives, and it does.
 *
 * Every method resolves through `ownPost()`, which scopes by the authenticated tenant and 404s
 * otherwise — never 403. A cross-tenant id must be indistinguishable from a nonexistent one, or
 * the endpoint tells a caller which post ids belong to their competitors.
 */
class MarketingPostsController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        $query = $tenant->marketingPosts()
            ->with(['asset', 'media'])
            ->orderByDesc('id');

        if (($status = $request->query('status')) && in_array($status, MarketingPost::STATUSES, true)) {
            $query->where('status', $status);
        }

        $posts = $query->paginate($this->perPage($request));

        return response()->json([
            'data' => MarketingPostResource::collection($posts)->resolve(),
            'meta' => $this->paginationMeta($posts),
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->ok(new MarketingPostResource($this->ownPost($request, $id)->load(['asset', 'media'])));
    }

    public function store(SaveMarketingPostRequest $request, SaveMarketingPostAction $action): JsonResponse
    {
        $post = $action->create($request->user(), $request->payload(), [
            'hero' => $request->file('hero'),
            'gallery' => $request->file('gallery', []),
        ]);

        return $this->ok(
            new MarketingPostResource($post->load(['asset', 'media'])),
            __('api.marketing_post_created'),
            201,
        );
    }

    public function update(SaveMarketingPostRequest $request, int $id, SaveMarketingPostAction $action): JsonResponse
    {
        $post = $action->update($this->ownPost($request, $id), $request->user(), $request->payload(), [
            'hero' => $request->file('hero'),
            'gallery' => $request->file('gallery', []),
        ]);

        return $this->ok(
            new MarketingPostResource($post->load(['asset', 'media'])),
            __('api.marketing_post_updated'),
        );
    }

    /** Send it to the mall for review. The only route out of `draft` a retailer has. */
    public function submit(Request $request, int $id, SubmitMarketingPostService $service): JsonResponse
    {
        $post = $service->handle($this->ownPost($request, $id));

        return $this->ok(
            new MarketingPostResource($post->load(['asset', 'media'])),
            __('api.marketing_post_submitted'),
        );
    }

    /** Pull it back before anyone reviewed it. */
    public function withdraw(Request $request, int $id, SubmitMarketingPostService $service): JsonResponse
    {
        $post = $service->withdraw($this->ownPost($request, $id));

        return $this->ok(
            new MarketingPostResource($post->load(['asset', 'media'])),
            __('api.marketing_post_withdrawn'),
        );
    }

    /**
     * Delete a draft. Only ever a draft or a returned post — once it is with the mall, or live, it
     * is not the retailer's to remove. Soft delete, so an operator can still see what happened.
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $post = $this->ownPost($request, $id);

        abort_unless($post->isEditableByTenant(), 422, __('api.marketing_post_not_editable'));

        $post->delete();

        return $this->ok(null, __('api.marketing_post_deleted'));
    }

    /**
     * GET /api/v1/me/feed — what is running in the malls this retailer trades in.
     *
     * Uses the PUBLIC resource deliberately: a retailer reading a NEIGHBOUR's campaign is a member
     * of the public as far as that campaign is concerned, and must not see its workflow state or
     * its performance numbers.
     *
     * The audience filter is skipped (`null`) rather than set to `tenants`, and that is the
     * asymmetry the `audience` column exists for. A retailer sees everything running in their
     * mall — the shopper-facing offers AND the tenants-only notices — because they are both a
     * member of the public and a member of the community. A shopper is only the former, so the
     * public endpoint pins `visitors` and never sees a staff discount.
     */
    public function feed(Request $request): JsonResponse
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        // The malls this retailer actually trades in — through the lease_unit pivot, so a
        // multi-unit lease's additional property is included.
        $assetIds = $tenant->activeLeases()
            ->with('units:id,asset_id')
            ->get()
            ->flatMap(fn ($lease) => $lease->units->pluck('asset_id'))
            ->unique()
            ->values();

        $posts = MarketingPost::query()
            ->whereIn('asset_id', $assetIds)
            ->liveFor(null)
            ->with(['tenant.media', 'media'])
            ->feedOrder()
            ->paginate($this->perPage($request));

        return response()->json([
            'data' => PublicMarketingPostResource::collection($posts)->resolve(),
            'meta' => $this->paginationMeta($posts),
        ]);
    }

    /** This tenant's post, or 404 — never 403. */
    private function ownPost(Request $request, int $id): MarketingPost
    {
        /** @var Tenant $tenant */
        $tenant = $request->user();

        $post = $tenant->marketingPosts()->whereKey($id)->first();

        abort_if($post === null, 404);

        return $post;
    }
}
