<?php

namespace App\Http\Requests\Api\V1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class DisputeTenantRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Required here AND in the service: this validates the shape, the service owns the rule
            // so the portal and the API refuse identically.
            'reason' => ['required', 'string', 'max:1000'],
        ];
    }
}
