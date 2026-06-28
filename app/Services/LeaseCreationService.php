<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class LeaseCreationService
{
    /**
     * Create a lease in one shot: optionally create the tenant, create the lease,
     * seed the standard Egypt charges (rent VAT-exempt + service charge 14% VAT),
     * and mark the unit as occupied.
     *
     * @param array{tenant_mode:string, tenant_id?:int|null, tenant?:array, lease:array} $payload
     */
    public function create(array $payload): Lease
    {
        return DB::transaction(function () use ($payload) {
            $tenant = $payload['tenant_mode'] === 'existing'
                ? Tenant::findOrFail($payload['tenant_id'])
                : $this->createTenant($payload['tenant']);

            $unit = Unit::with('asset')->findOrFail($payload['lease']['unit_id']);

            $hasActiveLease = Lease::where('unit_id', $unit->id)
                ->where('status', 'active')
                ->exists();

            if ($hasActiveLease) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'lease.unit_id' => __('admin.validation.unit_has_active_lease'),
                ]);
            }

            $assetCode = $unit->asset?->code ?? 'AW';

            $commencement = CarbonImmutable::parse($payload['lease']['commencement_date']);
            $termMonths = (int) $payload['lease']['term_months'];
            $expiry = $commencement->addMonths($termMonths)->subDay();
            $rent = (float) $payload['lease']['base_rent_monthly'];
            $service = (float) ($payload['lease']['service_charge_monthly'] ?? 0);

            $lease = Lease::create([
                'reference' => Lease::generateReference($assetCode),
                'unit_id' => $unit->id,
                'tenant_id' => $tenant->id,
                'status' => 'active',
                'commencement_date' => $commencement,
                'expiry_date' => $expiry,
                'term_months' => $termMonths,
                'base_rent_monthly' => $rent,
                'service_charge_monthly' => $service,
                'currency' => 'EGP',
                'security_deposit' => (float) ($payload['lease']['security_deposit'] ?? $rent * 3),
                'security_deposit_received' => false,
                'escalation_rate' => (float) ($payload['lease']['escalation_rate'] ?? 7),
                'escalation_type' => 'fixed_percent',
                'payment_terms_days' => (int) ($payload['lease']['payment_terms_days'] ?? 7),
            ]);

            self::seedStandardCharges($lease, $rent, $service, $commencement);

            // Unit status is projected by LeaseObserver from the lease's
            // 'active' status — no explicit flip needed here.

            return $lease;
        });
    }

    /**
     * Seed Egypt's standard rent + service-charge pair on a lease. Idempotent:
     * skips when the lease already has charges. Used by `create()` and by
     * `CreateLease::handleRecordCreation()` so the standard Filament form
     * gets the same charges the wizard produces.
     */
    public static function seedStandardCharges(
        Lease $lease,
        float $rent,
        float $service,
        ?\DateTimeInterface $commencement = null,
    ): void {
        if ($lease->charges()->exists()) {
            return;
        }

        $commencement = $commencement ?? $lease->commencement_date;

        if ($rent > 0) {
            Charge::create([
                'lease_id' => $lease->id,
                'name' => 'Base Rent',
                'type' => 'base_rent',
                'amount' => $rent,
                'currency' => $lease->currency ?? 'EGP',
                'frequency' => 'monthly',
                'vat_applicable' => false,
                'vat_rate' => 0,
                'start_date' => $commencement,
                'is_active' => true,
            ]);
        }

        if ($service > 0) {
            Charge::create([
                'lease_id' => $lease->id,
                'name' => 'Service Charge',
                'type' => 'service_charge',
                'amount' => $service,
                'currency' => $lease->currency ?? 'EGP',
                'frequency' => 'monthly',
                'vat_applicable' => true,
                'vat_rate' => 14.00,
                'start_date' => $commencement,
                'is_active' => true,
            ]);
        }

        // Marketing levy — a % of base rent charged to the tenant (VAT-exempt).
        // It bills as its own line item and funds the property's marketing budget.
        if ($rent > 0) {
            app(MarketingLevyService::class)->createLevyCharge($lease);
        }
    }

    private function createTenant(array $data): Tenant
    {
        return Tenant::create([
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'type' => $data['type'] ?? 'company',
            'email' => $data['email'] ?? null,
            'password' => Hash::make($data['password'] ?? 'password'),
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? $data['phone'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'contact_person_phone' => $data['contact_person_phone'] ?? null,
            'status' => 'active',
        ]);
    }
}
