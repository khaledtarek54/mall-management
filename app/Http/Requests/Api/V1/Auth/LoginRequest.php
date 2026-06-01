<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => ['required', 'email', 'max:255'],
            'password' => ['required', 'string', 'min:1'],
            // Optional per the mobile contract (the app doesn't send it). When
            // present it labels the token for the "manage devices" screen;
            // otherwise we fall back to the User-Agent (see deviceName()).
            'device_name' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }

    /**
     * The login contract uses 400 (not 422) for a missing/malformed body.
     */
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'message' => $validator->errors()->first(),
            'statusCode' => 400,
        ], 400));
    }

    public function email(): string
    {
        return strtolower(trim((string) $this->input('email')));
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }

    public function deviceName(): string
    {
        $name = trim((string) $this->input('device_name'));

        return $name !== '' ? $name : (substr((string) $this->userAgent(), 0, 100) ?: 'mobile');
    }
}
