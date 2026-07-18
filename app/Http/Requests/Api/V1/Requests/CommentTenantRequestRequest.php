<?php

namespace App\Http\Requests\Api\V1\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommentTenantRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:5000'],
        ];
    }
}
