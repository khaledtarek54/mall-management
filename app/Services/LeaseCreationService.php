<?php

namespace App\Services;

use App\Models\Charge;
use App\Models\Lease;
use App\Models\RentableItem;
use App\Models\Tenant;
use App\Models\Unit;
use App\Support\ChargeEscalation;
use App\Support\DepositBasis;
use App\Support\LeaseActivation;
use App\Support\LeaseTerm;
use App\Support\PropertySettings;
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
                // ENTRY EXECUTES only where the property says so (meeting 2026-09-02, point 1).
                // With `lease_activation_requires` at `none` — Yardi Commercial's default and the
                // shipped one — a lease entered through the wizard is active, exactly as before.
                // With money required it is entered AWAITING ACTIVATION, holds its shop as
                // `reserved`, and the Activate act is the only door to `active`.
                'status' => LeaseActivation::entryExecutes($unit->asset_id) ? 'active' : 'pending_approval',
                'commencement_date' => $commencement,
                'expiry_date' => $expiry,
                'term_months' => $termMonths,
                'base_rent_monthly' => $rent,
                'service_charge_monthly' => $service,
                'currency' => 'EGP',
                // The house policy, not a literal 3 (EG-35, finding M-11). Per-property, because
                // deposit terms are negotiated per building. An agreed figure still wins — and
                // since 2026-09-11 it wins as a FIXED basis, while a lease that states none takes
                // the property's basis (months or % of annual rent) and lets `Lease::saving`
                // derive the figure through `DepositBasis`, the one arithmetic every writer reads.
                // Before this the wizard wrote the derived SUM and no multiple, so a wizard lease's
                // deposit never tracked its rent the way a form lease's did (EG-35's own rule).
                ...self::depositTerms($payload, $unit->asset_id),
                'escalation_rate' => (float) ($payload['lease']['escalation_rate'] ?? 7),
                'escalation_type' => 'fixed_percent',
                // The property's convention, not a literal 7 — the same shape as the deposit
                // above. The lease FORM already pre-fills from this resolver, so the form path is
                // unchanged (it always sends an explicit value); what this reaches is every caller
                // that does NOT state terms — above all `LeaseImporter`, which has no such column,
                // so a migrating operator whose terms are 30 days imported every lease at 7.
                'payment_terms_days' => (int) ($payload['lease']['payment_terms_days']
                    ?? PropertySettings::paymentTermsDays($unit->asset_id)),
            ]);

            self::seedStandardCharges($lease, $rent, $service, $commencement);

            // Write the whole term's contracted rent steps now, not one anniversary at a time —
            // so the mall's future revenue is a recorded fact the day the lease is signed, and an
            // operator can review an increase before it bills. See ChargeScheduleService.
            app(ChargeScheduleService::class)->projectTermEscalations($lease->fresh());

            // The bays, cages and signage faces let WITH the lease (2026-09-12) — each through
            // `AssignRentableItemService::assign()`, the one door, dated from the commencement
            // unless the row says otherwise. A refusal here is a refusal of the whole create:
            // inside this transaction, and a wizard operator has the item in front of them.
            foreach ($payload['rentable_items'] ?? [] as $row) {
                $item = RentableItem::query()->find($row['rentable_item_id'] ?? null);

                if ($item === null) {
                    continue;
                }

                app(AssignRentableItemService::class)->assign($lease->fresh(), $item, [
                    'effective_from' => filled($row['effective_from'] ?? null) ? $row['effective_from'] : $commencement,
                    'monthly_rate' => filled($row['monthly_rate'] ?? null) ? (float) $row['monthly_rate'] : null,
                    'escalation_mode' => $row['escalation_mode'] ?? null,
                    'escalation_rate' => $row['escalation_rate'] ?? null,
                    'escalation_amount' => $row['escalation_amount'] ?? null,
                ]);
            }

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
    /**
     * The deposit columns a new lease is created with — agreed figure, else the property's basis.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private static function depositTerms(array $payload, ?int $assetId): array
    {
        if (isset($payload['lease']['security_deposit'])) {
            return [
                'security_deposit' => (float) $payload['lease']['security_deposit'],
                'security_deposit_basis' => DepositBasis::FIXED,
                'security_deposit_months' => null,
                'security_deposit_percent' => null,
            ];
        }

        $defaults = DepositBasis::defaultsFor($assetId);

        return [
            // Derived by `Lease::saving` from the basis; 0 here is a placeholder the hook replaces.
            'security_deposit' => 0.0,
            'security_deposit_basis' => $defaults['basis'],
            'security_deposit_months' => $defaults['months'],
            'security_deposit_percent' => $defaults['percent'],
        ];
    }

    /**
     * @param  array{escalation_mode?: ?string, escalation_rate?: ?float, escalation_amount?: ?float}  $serviceEscalation
     *                                                                                                                     how the service charge steps on the anniversary (meeting 2026-09-02, point 24) — the
     *                                                                                                                     lease form asks; a caller that says nothing gets the PROPERTY's proposal
     *                                                                                                                     (`ChargeEscalation::defaultModeFor()`), which is what the wizard and the importer
     *                                                                                                                     take, so a migrating operator's file lands under the mall's own convention.
     */
    public static function seedStandardCharges(
        Lease $lease,
        float $rent,
        float $service,
        ?\DateTimeInterface $commencement = null,
        array $serviceEscalation = [],
    ): void {
        if ($lease->charges()->exists()) {
            return;
        }

        $commencement = $commencement ?? $lease->commencement_date;

        $serviceEscalation += [
            'escalation_mode' => ChargeEscalation::defaultModeFor($lease->unit?->asset_id),
            'escalation_rate' => null,
            'escalation_amount' => null,
        ];

        if ($rent > 0) {
            Charge::create([
                'lease_id' => $lease->id,
                'name' => 'Base Rent',
                'type' => 'base_rent',
                'amount' => $rent,
                'currency' => $lease->currency ?? 'EGP',
                'frequency' => 'monthly',
                // Taxability comes from the charge code, not from here — and until 2026-08-22 these
                // two lines said the opposite of that comment, freezing BOTH the answer and the rate
                // onto the row. Omitted entirely now: null on both means `Charge::resolvedVatRate()`
                // asks the catalogue for the date being billed. The service-charge block below has
                // been right about the rate since 2026-08-12; this one was two lines above it.
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
                // null on both = the catalogue answers at billing time (Charge::resolvedVatRate);
                // a value on either is an override somebody chose.
                'vat_rate' => null,
                // The row's own annual-increase rule — the model clears the figure its mode
                // does not read.
                'escalation_mode' => $serviceEscalation['escalation_mode'],
                'escalation_rate' => $serviceEscalation['escalation_rate'],
                'escalation_amount' => $serviceEscalation['escalation_amount'],
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
