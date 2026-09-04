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
            // The retailer's own number. They are the party who gets asked to quote it, so
            // withholding it from `GET /me` left the mobile app unable to show them the one
            // identifier the mall will ask for on the phone. Nullable like `legal_name`: rows
            // predating the code carry none until the backfill has run.
            'code' => $this->code,
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
            // **The language this tenant is WRITTEN to in**, and the one thing the app could not
            // reach. `Accept-Language` governs a JSON response and a PDF the app downloads, because
            // there the caller IS the recipient — but a PUSH is not a request and has no header, so
            // Laravel renders it under `HasLocalePreference`, which reads this column. The same
            // goes for e-mail and for an invoice the OPERATOR sends. The column has been fillable
            // and `preferredLocale()` has read it since 2026-08-12; no API could set it, so it
            // answered null for every tenant and the app's language toggle silently reached none of
            // those three channels.
            //
            // Clamped to `SetLocale::SUPPORTED` on the way in (see UpdateProfileRequest): an
            // unsupported value does not throw, it makes `__()` fall through to the fallback
            // locale — so a typo leaves the column looking set and every document in English.
            'locale' => $this->locale,
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
