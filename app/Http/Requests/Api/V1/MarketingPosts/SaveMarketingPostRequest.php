<?php

namespace App\Http\Requests\Api\V1\MarketingPosts;

use App\Models\MarketingPost;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Validation for a retailer creating or editing their own post from the mobile app.
 *
 * Two fields a client MAY NOT set are deliberately absent from the rules, and their absence is the
 * point: `status` and `is_featured`. Accepting either would let a retailer publish straight to the
 * mall's feed or promote themselves into the carousel — the two things the whole review workflow
 * exists to prevent. They are not validated-and-rejected, they are simply never read
 * ({@see \App\Actions\Api\V1\MarketingPosts\SaveMarketingPostAction} builds its own attribute
 * list), so a future edit that adds a rule here still cannot hand them over.
 *
 * `asset_id` IS accepted, because a chain trading in three of the operator's malls has to say
 * which one — but it is re-checked server-side against the tenant's actual leases in
 * {@see \App\Services\MarketingPost\SubmitMarketingPostService::assertTenantTradesIn()}. The rule
 * below is a fast fail, not the guard.
 */
class SaveMarketingPostRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        // PATCH sends a subset; POST must carry the essentials.
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'asset_id' => [$required, 'integer', Rule::exists('assets', 'id')],
            'type' => ['sometimes', Rule::in(MarketingPost::TYPES)],
            // Audience is capped at what a retailer may legitimately choose. `tenants` is allowed
            // (a staff discount for the mall's other retailers is a real thing they publish).
            'audience' => ['sometimes', Rule::in(MarketingPost::AUDIENCES)],

            'title' => [$required, 'string', 'max:255'],
            'title_ar' => ['nullable', 'string', 'max:255'],
            'summary' => ['nullable', 'string', 'max:500'],
            'summary_ar' => ['nullable', 'string', 'max:500'],
            'body' => ['nullable', 'string', 'max:5000'],
            'body_ar' => ['nullable', 'string', 'max:5000'],
            'terms' => ['nullable', 'string', 'max:2000'],
            'terms_ar' => ['nullable', 'string', 'max:2000'],
            'discount_label' => ['nullable', 'string', 'max:60'],
            'discount_label_ar' => ['nullable', 'string', 'max:60'],

            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date', 'after_or_equal:starts_at'],

            'cta_label' => ['nullable', 'string', 'max:60'],
            'cta_label_ar' => ['nullable', 'string', 'max:60'],
            // `url` rather than a bare string: the value is rendered as a tappable link in a
            // shopper's app, and `javascript:` in that slot is a stored-XSS delivery mechanism.
            'cta_url' => ['nullable', 'url', 'max:500'],

            // Artwork. mimetypes (not mimes) validates the real content type, so a renamed file
            // cannot slip through — same rule the sales-report upload uses.
            'hero' => ['nullable', 'file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
            'gallery' => ['nullable', 'array', 'max:6'],
            'gallery.*' => ['file', 'mimetypes:image/jpeg,image/png,image/webp', 'max:5120'],
        ];
    }

    /**
     * The scalar attributes the action persists. Explicitly enumerated — see the class docblock
     * for why `status` and `is_featured` can never appear here.
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->only([
            'asset_id', 'type', 'audience',
            'title', 'title_ar', 'summary', 'summary_ar', 'body', 'body_ar',
            'terms', 'terms_ar', 'discount_label', 'discount_label_ar',
            'starts_at', 'ends_at',
            'cta_label', 'cta_label_ar', 'cta_url',
        ]);
    }
}
