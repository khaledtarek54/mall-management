<?php

namespace App\Actions\Api\V1\Sales;

use App\Models\TenantSalesDeclaration;
use App\Models\Tenant;
use App\Services\PercentageRentCalculationService;
use Illuminate\Validation\ValidationException;

/**
 * Submit a sales declaration for a percentage-rent lease.
 *
 * Guards (all server-enforced — never trust the client):
 *  - the lease must belong to this tenant AND carry percentage-rent terms;
 *  - one declaration per (lease, period_start) — matches the DB unique key.
 *
 * On success it persists the declaration as the tenant (polymorphic author)
 * and runs the shared calculation service so calculated_percentage_rent is
 * populated immediately. It does NOT lock — locking is a staff action.
 */
class CreateSalesDeclarationAction
{
    public function __construct(private PercentageRentCalculationService $service) {}

    /**
     * @param  array<string,mixed>  $data  Keys: lease_id, period_start, period_end, declared_sales
     */
    public function handle(Tenant $tenant, array $data): TenantSalesDeclaration
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
            'declared_sales' => $data['declared_sales'],
            'status' => 'submitted',
            'declared_at' => now(),
        ]);
        $declaration->declaredBy()->associate($tenant);
        $declaration->save();

        // Populate calculated_percentage_rent right away so the app can show
        // the tenant their estimated liability before staff lock it.
        return $this->service->recalculate($declaration);
    }
}
