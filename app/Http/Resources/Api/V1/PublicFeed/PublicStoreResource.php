<?php

namespace App\Http\Resources\Api\V1\PublicFeed;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What an unauthenticated shopper is allowed to see of a RETAILER.
 *
 * `Tenant` is the single most sensitive non-money model in the system: it carries the commercial
 * register, the tax card number, the national ID of a sole trader, the leasing contact's mobile,
 * and — through relations — the entire rent ledger. None of that is here, and none of it can
 * arrive by accident, because this class names its fields rather than filtering them.
 *
 * That is the whole reason the store directory did not simply expose the `tenants` row. A
 * "public" flag on a model whose other columns are confidential protects nothing on its own; the
 * protection is that the only path to a shopper runs through this allowlist.
 *
 * Note `storeName()` — the shopper is shown the sign above the door (`trade_name`), falling back
 * to `name`. `legal_name` is never sent: "Crema Coffee Co. LLC" is not a thing anyone is looking
 * for, and pairing a brand with its legal entity is exactly the kind of detail a directory has no
 * business publishing.
 *
 * @mixin Tenant
 */
class PublicStoreResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'name' => $this->storeName('en'),
            'name_ar' => $this->trade_name_ar,
            'retail_category' => $this->retail_category,
            'description' => $this->public_description,
            'description_ar' => $this->public_description_ar,
            'website_url' => $this->website_url,
            'instagram_handle' => $this->instagram_handle,
            'logo_url' => $this->logoUrl(),

            // Where to find it, when the caller asked for a specific mall and we resolved the
            // units this retailer occupies THERE. A bare unit code is not commercially sensitive
            // (it is painted on the shopfront), but it is only ever scoped to the mall being
            // browsed — never the retailer's presence across the operator's whole portfolio,
            // which would let anyone map a chain's footprint from a public endpoint.
            'locations' => $this->when(
                isset($this->public_locations),
                fn () => $this->public_locations,
            ),
        ];
    }
}
