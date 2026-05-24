<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

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
            // device_name lets the mobile app label tokens per device
            // (e.g. "Khaled's iPhone 16") for the "manage devices" screen.
            'device_name' => ['required', 'string', 'max:100'],
        ];
    }

    public function email(): string
    {
        return strtolower(trim($this->input('email')));
    }

    public function password(): string
    {
        return (string) $this->input('password');
    }

    public function deviceName(): string
    {
        return trim($this->input('device_name'));
    }
}
