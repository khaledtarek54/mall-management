<?php

namespace App\Http\Requests\Api\V1\Maintenance;

use App\Models\MaintenanceRequest;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreateMaintenanceRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            'category' => ['required', Rule::in(MaintenanceRequest::CATEGORIES)],
            'priority' => ['sometimes', Rule::in(MaintenanceRequest::PRIORITIES)],
            // If supplied, the unit must belong to one of THIS tenant's leases.
            // Prevents a tenant from filing against someone else's unit. When
            // omitted, the service derives it from the active lease.
            'unit_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('leases', 'unit_id')->where('tenant_id', $this->user()->id),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return [
            'title' => $this->input('title'),
            'description' => $this->input('description'),
            'category' => $this->input('category'),
            'priority' => $this->input('priority', 'medium'),
            'unit_id' => $this->input('unit_id'),
        ];
    }
}
