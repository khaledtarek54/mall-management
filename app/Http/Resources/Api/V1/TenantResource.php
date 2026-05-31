<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TenantResource extends JsonResource
{
    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'legal_name' => $this->legal_name,
            'type' => $this->type,
            'email' => $this->email,
            'phone' => $this->phone,
            'whatsapp' => $this->whatsapp,
            'contact_person' => $this->contact_person,
            'status' => $this->status,
            // Deliberately re-exposed despite Tenant::$hidden. Mobile invoice
            // displays + ETA submissions need it; national_id stays hidden
            // (more sensitive PII). Confirmed at audit M02 F-7 / D-5.
            'tax_id' => $this->tax_id,
        ];
    }
}
