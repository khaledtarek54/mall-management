<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\MaintenanceRequestComment
 */
class MaintenanceRequestCommentResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        // The author is polymorphic (Tenant or staff User). We never leak a
        // staff member's identity beyond "the property team", and internal
        // comments are filtered out before they ever reach this resource.
        $isTenant = $this->author_type === (new Tenant)->getMorphClass();

        return [
            'id' => $this->id,
            'body' => $this->body,
            'author_kind' => $isTenant ? 'tenant' : 'staff',
            'author_name' => $isTenant
                ? optional($this->author)->name
                : __('Property team'),
            'created_at' => optional($this->created_at)->toIso8601String(),
        ];
    }
}
