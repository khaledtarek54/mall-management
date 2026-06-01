<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateSalesDeclarationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // Lease must belong to this tenant. The percentage-rent check and
            // duplicate-period check carry richer messages and live in the
            // action; here we just establish ownership.
            'lease_id' => [
                'required',
                'integer',
                Rule::exists('leases', 'id')->where('tenant_id', $this->user()->id),
            ],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'declared_sales' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->only(['lease_id', 'period_start', 'period_end', 'declared_sales']);
    }
}
