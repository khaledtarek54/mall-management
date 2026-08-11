<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\Vat;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LeaseCreationService
{
    /**
     * Create a lease in one shot: optionally create the tenant, create the lease,
     * seed the standard Egypt charges (rent VAT-exempt + service charge 14% VAT),
     * and mark the unit as occupied.
     *
     * @param  array{tenant_mode:string, tenant_id?:int|null, tenant?:array, lease:array}  $payload
     */
    public function create(array $payload): Lease
    {
        return DB::transaction(function () use ($payload) {
            $tenant = $payload['tenant_mode'] === 'existing'
                ? Tenant::findOrFail($payload['tenant_id'])
                : $this->createTenant($payload['tenant']);

            // lockForUpdate, not a plain read. The isActivelyLeased() check below is check-then-act:
            // under MySQL's REPEATABLE READ a snapshot read cannot see another transaction's
            // uncommitted lease, so two concurrent creates on the same unit — a double-clicked
            // "Create lease", two leasing agents on the same shop — both find it free and both
            // commit. There is no unique constraint to catch the second one; the result is a
            // double-booked unit billed twice a month. Locking the unit row makes the guard
            // authoritative: the second transaction waits, then sees the first lease and is
            // refused. Same row the renewal path locks.
            $unit = Unit::with('asset')->lockForUpdate()->findOrFail($payload['lease']['unit_id']);

            // Pivot-aware (master OR additional unit) — see Unit::isActivelyLeased().
            if ($unit->isActivelyLeased()) {
                throw ValidationException::withMessages([
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

            // Write the whole term's contracted rent steps now, not one anniversary at a time —
            // so the mall's future revenue is a recorded fact the day the lease is signed, and an
            // operator can review an increase before it bills. See ChargeScheduleService.
            app(ChargeScheduleService::class)->projectTermEscalations($lease->fresh());

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
                // Taxability comes from the charge code, not from here — the catalogue ships rent
                // exempt and the accountant owns any change to that.
                'vat_applicable' => Vat::rateForType('base_rent') > 0,
                'vat_rate' => Vat::rateForType('base_rent'),
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
                'vat_applicable' => Vat::rateForType('service_charge') > 0,
                'vat_rate' => Vat::rateForType('service_charge'),
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
            // NEVER a shared default. `tenants.password` is the Sanctum credential for
            // /api/v1 (LoginTenantAction), and the quick-lease wizard has no password field — so
            // `?? 'password'` did not mean "a sensible default", it meant EVERY tenant onboarded
            // through the wizard shared one guessable credential, and knowing a retailer's email
            // address was enough to authenticate as that company.
            //
            // An unusable random secret is the correct absence of a password: the company exists,
            // nobody can sign in, and the operator issues real credentials through the audited
            // "Setup Portal Access" action, which is already gated on super_admin/manager and
            // shows the generated password exactly once.
            'password' => Hash::make($data['password'] ?? Str::password(32)),
            'phone' => $data['phone'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? $data['phone'] ?? null,
            'tax_id' => $data['tax_id'] ?? null,
            'contact_person' => $data['contact_person'] ?? null,
            'contact_person_phone' => $data['contact_person_phone'] ?? null,
            'status' => 'active',
        ]);
    }
}
