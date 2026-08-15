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
            // Both of these are ACCEPTED and stored by UpdateProfileRequest, so
            // withholding them here made them write-only: a tenant edited their
            // address, saved, reopened the screen and it was gone. The standing
            // rule for this resource is that whatever `PATCH /me` takes, `GET /me`
            // gives back — otherwise the app cannot render its own edit form.
            'contact_person_phone' => $this->contact_person_phone,
            'address' => $this->address,
            'status' => $this->status,
            // Deliberately re-exposed despite Tenant::$hidden. Mobile invoice
            // displays + ETA submissions need it; national_id stays hidden
            // (more sensitive PII). Confirmed at audit M02 F-7 / D-5.
            'tax_id' => $this->tax_id,
            // The store's brand mark, for the app's avatar. Safe to send as a
            // plain URL: `logo` is the one PUBLIC-disk collection on this model
            // (the shopper directory renders it unauthenticated), unlike
            // `documents`, which shares the model and stays private. Null → the
            // client falls back to initials.
            'logo_url' => $this->logoUrl(),
        ];
    }
}
