<?php

namespace App\Filament\Admin\Resources\Leases\Pages;

use App\Filament\Admin\Resources\Leases\LeaseResource;
use App\Models\Lease;
use App\Services\ChargeScheduleService;
use App\Services\LeaseCreationService;
use Filament\Resources\Pages\CreateRecord;

class CreateLease extends CreateRecord
{
    /**
     * The lease and its charge ladder are ONE unit of work.
     *
     * `afterCreate()` seeds the standard charges, projects the whole term's contracted rent and
     * creates the marketing levy — several writes after the lease row is already committed. Nothing
     * on that path throws TODAY: `ChargeScheduleService`'s two refusals are both inside
     * `overlayWindow()`, the rent-relief path this page never calls, and `projectTermEscalations()`
     * returns early unless an escalation is configured. (An earlier version of this docblock said
     * otherwise and sent the reader to the wrong service — corrected in review.)
     *
     * So this is belt-and-braces rather than a live bug: a throw anywhere in that sequence — a
     * `ValueSets` refusal, a `Charge::booted()` guard, a future step — would otherwise leave a
     * COMMITTED LEASE WITH A PARTIAL LADDER, which is the state `atriom:audit-charge-schedules`
     * exists to find after the fact. The page next door, `EditLease`, has the LIVE version of the
     * same shape.
     *
     * Filament's `CreateRecord::create()` already rolls back and re-throws; it is inert only because
     * no panel opts in (SW-003d). This page has no `halt()` after creation, so nothing else is
     * needed here — but note that `halt()` COMMITS by default, so a page that refuses that way must
     * pass `shouldRollbackDatabaseTransaction: true`.
     */
    protected ?bool $hasDatabaseTransactions = true;

    protected static string $resource = LeaseResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The lease's property is its master unit's; every additional unit must
        // share it. Re-validate against the user's visible set (property isolation).
        LeaseResource::assertUnitAssetInScope($data['unit_id'] ?? null);
        LeaseResource::assertUnitsAssetInScope($this->data['additional_unit_ids'] ?? []);

        return $data;
    }

    /**
     * Standard Filament form creates the Lease row via Eloquent's default
     * flow. LeaseObserver handles the unit-status flip (active → occupied).
     * Charges aren't part of the form, so seed them here using the same
     * Egypt-VAT defaults the Quick New Lease wizard produces.
     *
     * **The order of the three steps is load-bearing** — premises, then rent, then the charges
     * derived from it. See the comment on the first block.
     */
    protected function afterCreate(): void
    {
        /** @var Lease $lease */
        $lease = $this->record;

        // ── THE PREMISES ARE ATTACHED FIRST, BECAUSE EVERYTHING BELOW IS PRICED FROM THEM ─────
        //
        // This block used to run LAST, after the charges were seeded and the whole term's ladder
        // projected — so on a rate-priced lease every one of those figures was built from the
        // MASTER unit's area alone. `Lease::saving` derives the rent from the `lease_unit` pivot,
        // and at save time that pivot is empty (the observer writes the master row in `created`),
        // so the derivation fell through to its own master-unit fallback and nothing re-asked
        // once the second shop was attached.
        //
        // Measured on Val Plaza: A-03 (90 m²) + A-04 (120 m²) at 1,000/m²/yr saved 7,500 a month
        // where 17,500 was due — ladder 7,500 → 8,025 → 8,586.75, levy 375, deposit 22,500 — a
        // lease under-billed by 10,000 a month for three years, with nothing on screen to say so.
        //
        // Nothing has billed yet at this point, so there is no effective date to honour and no
        // month to restate; that is exactly the boundary `repriceFromPremises()` states and
        // enforces. A LIVE lease taking more space is `LeaseSpaceChangeService`'s act instead.
        $additional = $this->data['additional_unit_ids'] ?? [];

        if (! empty($additional)) {
            $lease->syncUnits([$lease->unit_id, ...$additional], $lease->unit_id);
            $lease->load('units');
            $lease->repriceFromPremises();
        }

        LeaseCreationService::seedStandardCharges(
            $lease,
            rent: (float) $lease->base_rent_monthly,
            service: (float) $lease->service_charge_monthly,
        );

        // Project the whole term's contracted rent ladder — LS-01, and the thing this page did not
        // do (2026-08-16). `LeaseCreationService::create()` has always projected, but that service
        // is reached only from the "Quick new lease" wizard on the list header; the standard New
        // lease form runs Eloquent directly and stopped at the three seeded rows. So the SAME deal,
        // entered through the button an operator actually uses, produced a lease whose future rent
        // existed nowhere — while one entered through the wizard produced the full ladder. Two
        // creation paths in one panel disagreeing about what a lease is.
        //
        // Idempotent and safe after the seed: `setAmount()` writes a row only where the amount is
        // not already in force, and the anniversary sweep later recomputes the same figures and
        // finds them present.
        app(ChargeScheduleService::class)->projectTermEscalations($lease->fresh());
    }
}
