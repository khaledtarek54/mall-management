<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string,string>
     */
    public function credentials(): array
    {
        return [
            'email' => strtolower(trim($this->input('email'))),
            'password' => $this->input('password'),
            'password_confirmation' => $this->input('password_confirmation'),
            'token' => $this->input('token'),
        ];
    }
}
