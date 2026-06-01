<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The flat lease shape the mobile app's login screen consumes
 * (maps directly to the Flutter `Lease` model). Keys are emitted snake_case
 * and camelCased by CamelCaseResponseKeys → unit_number ⇒ unitNumber, etc.
 *
 * Field mapping (confirm `name`/`shop` with the front-end dev):
 *  - name       → tenant contact person, falling back to the tenant/company name
 *  - shop       → tenant (store/business) name
 *  - mall       → asset name
 *  - unit_number→ unit code
 *
 * @mixin \App\Models\Lease
 */
class LoginLeaseResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        $tenant = $this->tenant;
        $unit = $this->unit;
        $asset = $unit?->asset;

        return [
            'id' => $this->id,
            'name' => $tenant?->contact_person ?: $tenant?->name,
            'shop' => $tenant?->name,
            'mall' => $asset?->name,
            'unit_number' => $unit?->code,
            'start_date' => $this->commencement_date?->utc()->toIso8601ZuluString('millisecond'),
            'end_date' => $this->expiry_date?->utc()->toIso8601ZuluString('millisecond'),
            'is_active' => $this->status === 'active',
        ];
    }
}
