<?php

namespace App\Actions\Api\V1\Sales;

use App\Models\TenantSalesDeclaration;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Submit a sales declaration for a percentage-rent lease.
 *
 * The tenant no longer self-reports a figure — they attach their sales report
 * file. The operator reviews the attachment in the admin panel, enters the
 * sales figure, and locks the declaration to generate the percentage-rent
 * charge. So this action does NOT calculate anything at submission time; it
 * persists the declaration (figure null) plus the uploaded file(s).
 *
 * Guards (all server-enforced — never trust the client):
 *  - the lease must belong to this tenant AND carry percentage-rent terms;
 *  - one declaration per (lease, period_start) — matches the DB unique key.
 */
class CreateSalesDeclarationAction
{
    /**
     * @param  array<string,mixed>  $data  Keys: lease_id, period_start, period_end
     * @param  array<int, UploadedFile>  $attachments  The tenant's sales-report file(s).
     */
    public function handle(Tenant $tenant, array $data, array $attachments = []): TenantSalesDeclaration
    {
        $lease = $tenant->leases()
            ->where('id', $data['lease_id'])
            ->where('has_percentage_rent', true)
            ->first();

        if (! $lease) {
            throw ValidationException::withMessages([
                'lease_id' => [__('api.sales_declaration_not_allowed')],
            ]);
        }

        $exists = TenantSalesDeclaration::where('lease_id', $lease->id)
            ->whereDate('period_start', $data['period_start'])
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'period_start' => [__('api.sales_declaration_duplicate')],
            ]);
        }

        $declaration = new TenantSalesDeclaration([
            'lease_id' => $lease->id,
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            // declared_sales stays null — the operator fills it after reviewing
            // the attached report. calculated_percentage_rent stays at its 0
            // default until then.
            'status' => 'submitted',
            'declared_at' => now(),
        ]);
        $declaration->declaredBy()->associate($tenant);
        $declaration->save();

        // Push the uploaded report into the Spatie `sales_report` collection.
        // Done after the row saves (not in a DB transaction) because media
        // moves files on disk — mirrors CreateTenantRequestAction.
        foreach ($attachments as $file) {
            $declaration->addMedia($file)->toMediaCollection(TenantSalesDeclaration::REPORT_COLLECTION);
        }

        return $declaration->load('lease', 'media');
    }
}
