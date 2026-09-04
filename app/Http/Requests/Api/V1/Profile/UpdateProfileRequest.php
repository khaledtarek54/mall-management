<?php

namespace App\Http\Requests\Api\V1\Profile;

use App\Http\Middleware\SetLocale;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Tenant self-service profile edit. Deliberately narrow: only contact fields
 * the tenant owns. name / legal_name / email / status / tax_id / national_id
 * are admin-managed and NOT editable here (audit M02 F-9).
 */
class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'whatsapp' => ['sometimes', 'nullable', 'string', 'max:32'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_person_phone' => ['sometimes', 'nullable', 'string', 'max:32'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // The language the tenant is WRITTEN to in — push, e-mail, and any document the
            // operator produces for them. Editable here because it is a preference about them, in
            // the same family as their phone number, and because nothing else could ever set it:
            // `preferredLocale()` has read this column since 2026-08-12 and no screen or endpoint
            // has ever written it.
            //
            // Validated against the ONE supported list rather than a copy. An unsupported locale
            // does not throw at render time — `__()` falls silently through to the fallback — so an
            // unvalidated `fr-CA` leaves the column looking set while every document arrives in
            // English, which is the failure mode that makes this a hard rule rather than a nicety.
            'locale' => ['sometimes', 'nullable', Rule::in(SetLocale::SUPPORTED)],
        ];
    }

    /**
     * Only the keys actually present in the request, so a PATCH with a single
     * field doesn't null out the others.
     *
     * @return array<string,mixed>
     */
    public function editableData(): array
    {
        return $this->only([
            'phone',
            'whatsapp',
            'contact_person',
            'contact_person_phone',
            'address',
            'locale',
        ]);
    }
}
