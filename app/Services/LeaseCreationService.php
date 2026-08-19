<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\LeaseTerm;
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

            // lockForUpdate, not a plain read. The check below is check-then-act: two concurrent
            // creates on the same unit — a double-clicked "Create lease", two leasing agents on the
            // same shop — would otherwise both find it free and both commit, leaving a
            // double-booked unit billed twice a month. Locking the unit row serialises them: the
            // second transaction waits here until the first commits. Same row the renewal path locks.
            //
            // The lock is only half of it, and the half that was missing until 2026-08-19 is the
            // read underneath. Waiting is not seeing: under REPEATABLE READ the guard's own query
            // is served from a snapshot taken at this transaction's FIRST plain read — the tenant
            // lookup on the line above — so it looked past the very lease it was waiting for.
            // `isActivelyLeasedForUpdate()` is a locking read and therefore reads the latest
            // committed state. Proven with two processes on two connections (F-09).
            $unit = Unit::with('asset')->lockForUpdate()->findOrFail($payload['lease']['unit_id']);

            // Pivot-aware (master OR additional unit), and a LOCKING read — see
            // Unit::isActivelyLeasedForUpdate(). The row lock above serialises the two writers;
            // only a locking read here can SEE what the one that went first committed.
            if ($unit->isActivelyLeasedForUpdate()) {
                throw ValidationException::withMessages([
                    'lease.unit_id' => __('admin.validation.unit_has_active_lease'),
                ]);
            }

            $commencement = CarbonImmutable::parse($payload['lease']['commencement_date']);
            $termMonths = (int) $payload['lease']['term_months'];
            // The one rule, shared with renewal and with the form — see App\Support\LeaseTerm.
            $expiry = CarbonImmutable::parse(LeaseTerm::expiryFrom($commencement, $termMonths));
            $rent = (float) $payload['lease']['base_rent_monthly'];
            $service = (float) ($payload['lease']['service_charge_monthly'] ?? 0);

            $lease = Lease::create([
                // Reference deliberately NOT set here (2026-08-19). `Lease::creating` allocates it
                // under the document-number lock, and that hook returns early when a reference is
                // already filled — so pre-computing one here bypassed the lock entirely. Reproduced
                // with two processes: both computed `LSE-AW-2026-0034` and one died on the unique
                // index (pre-staging QA, F-10). The model derives the same property code from the
                // unit it is being given.
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
                // null = the catalogue answers at billing time (Charge::resolvedVatRate); a value is an override.
                'vat_rate' => null,
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
