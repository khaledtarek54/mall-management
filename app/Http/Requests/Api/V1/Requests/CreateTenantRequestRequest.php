<?php

namespace App\Http\Requests\Api\V1\Maintenance;

use App\Enums\TenantRequestType;
use App\Models\TenantRequest;
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
        // The type drives which sub-categories are valid. Omitted → maintenance,
        // so existing app builds that only send `category` keep working.
        $type = TenantRequestType::tryFrom((string) $this->input('request_type')) ?? TenantRequestType::default();
        $subcategories = $type->subcategories();

        return [
            'request_type' => ['sometimes', Rule::in(TenantRequestType::values())],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:5000'],
            // Sub-category is required for types that define one (maintenance,
            // access, document, complaint) and validated against that type's set;
            // types without sub-categories (inquiry, billing) must NOT send one
            // (`prohibited` → 422 if they do, so no cross-type value is persisted).
            'category' => $subcategories === []
                ? ['prohibited']
                : ['required', Rule::in($subcategories)],
            'priority' => ['sometimes', Rule::in(TenantRequest::PRIORITIES)],
            // If supplied, the unit must belong to one of THIS tenant's leases.
            // Prevents a tenant from filing against someone else's unit. When
            // omitted, the service derives it from the active lease.
            'unit_id' => [
                'sometimes',
                'nullable',
                'integer',
                Rule::exists('leases', 'unit_id')->where('tenant_id', $this->user()->id),
            ],
            // Optional photos / PDFs of the issue. Mirrors the portal upload
            // (images + PDF only, ≤10 MB each, ≤5 files) so what a tenant can
            // attach from the app matches what staff can attach in the admin.
            // mimetypes (not mimes) validates the real content type, so a
            // renamed .mp4 can't slip through as a .jpg.
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => [
                'file',
                'mimetypes:image/jpeg,image/png,image/gif,image/webp,image/heic,image/heif,application/pdf',
                'max:10240',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return [
            'request_type' => $this->input('request_type', TenantRequestType::default()->value),
            'title' => $this->input('title'),
            'description' => $this->input('description'),
            'category' => $this->input('category'),
            'priority' => $this->input('priority', 'medium'),
            'unit_id' => $this->input('unit_id'),
        ];
    }

    /**
     * Uploaded attachment files, if any. Kept out of payload() — which carries
     * the scalar attributes the service persists — so the action can push these
     * into the Spatie `attachments` media collection after the request is saved.
     *
     * @return array<int, \Illuminate\Http\UploadedFile>
     */
    public function attachments(): array
    {
        return $this->file('attachments', []);
    }
}
