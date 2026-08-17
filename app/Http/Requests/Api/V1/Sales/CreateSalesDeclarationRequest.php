<?php

namespace App\Http\Requests\Api\V1\Sales;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
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
            // The tenant no longer types a figure — they attach their sales
            // report and staff read the number off it, then lock to bill the
            // percentage rent. At least one file is required. Mirrors the
            // maintenance upload (images + PDF, ≤10 MB each, ≤5 files). Using
            // mimetypes (not mimes) validates the real content type, so a
            // renamed .mp4 can't slip through as a .jpg.
            'attachments' => ['required', 'array', 'min:1', 'max:5'],
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
    public function messages(): array
    {
        return [
            'attachments.required' => __('api.sales_declaration_report_required'),
            'attachments.min' => __('api.sales_declaration_report_required'),
        ];
    }

    /**
     * Scalar attributes the action persists — the sales figure is intentionally
     * absent (operator-entered after reviewing the attachment).
     *
     * @return array<string,mixed>
     */
    public function payload(): array
    {
        return $this->only(['lease_id', 'period_start', 'period_end']);
    }

    /**
     * Uploaded sales-report files. Kept out of payload() so the action can push
     * them into the Spatie `sales_report` media collection after the row saves.
     *
     * @return array<int, UploadedFile>
     */
    public function attachments(): array
    {
        return $this->file('attachments', []);
    }
}
