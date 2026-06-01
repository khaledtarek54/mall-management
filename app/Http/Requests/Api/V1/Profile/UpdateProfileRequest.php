<?php

namespace App\Http\Requests\Api\V1\Profile;

use Illuminate\Foundation\Http\FormRequest;

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
        ]);
    }
}
