<?php

namespace App\Http\Requests\Api\V1\Devices;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RegisterDeviceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'platform' => ['required', Rule::in(['ios', 'android'])],
            'token' => ['required', 'string', 'max:512'],
            'device_name' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->only(['platform', 'token', 'device_name']);
    }
}
