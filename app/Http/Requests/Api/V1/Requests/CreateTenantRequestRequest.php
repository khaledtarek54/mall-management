<?php

namespace App\Http\Requests\Api\V1\Requests;

use App\Enums\TenantRequestType;
use App\Models\TenantRequest;
use App\Models\TenantRequestSubcategory;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;

class CreateTenantRequestRequest extends FormRequest
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
        // The CATALOGUE, so the mobile app validates against the same list the panel and portal
        // offer. Reading the enum here would refuse `elevator` — a subcategory the operator can see
        // in both web forms — with a validation error naming no reason.
        $subcategories = array_keys(TenantRequestSubcategory::optionsFor($type));

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
            // Matched against the lease_unit PIVOT, not just `leases.unit_id`: the column holds
            // only a multi-unit lease's MASTER, so an exists-rule on it rejected a tenant's own
            // additional units with "the selected unit id is invalid".
            'unit_id' => [
                'sometimes',
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail): void {
                    if ($value === null) {
                        return;
                    }

                    $ownsUnit = $this->user()->leases()
                        ->where(fn ($q) => $q
                            ->where('unit_id', $value)
                            ->orWhereHas('units', fn ($u) => $u->whereKey($value)))
                        ->exists();

                    if (! $ownsUnit) {
                        $fail(__('validation.exists', ['attribute' => $attribute]));
                    }
                },
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
     * @return array<int, UploadedFile>
     */
    public function attachments(): array
    {
        return $this->file('attachments', []);
    }
}
