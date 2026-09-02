<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Lease;
use App\Models\RentableItem;
use App\Models\Unit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The tenant's own lease terms.
 *
 * **The portal and `/api/v1` are the same surface with different renderers** — a rule this project
 * states twice and did not honour here. `Filament\Portal\Resources\Leases\Schemas\LeaseInfolist`
 * rendered fifteen commercial terms this resource did not publish, so a tenant could read their own
 * contract on the web and not in the app. The gap was found by comparing the two, which is now
 * `PortalAndApiAnswerTheSameQuestionsConformanceTest`'s job rather than a person's.
 *
 * Three of those omissions were more than incompleteness:
 *
 *   - **`deposit_outstanding`.** A deposit shortfall is never invoiced — the portal's own comment
 *     says so — which makes this figure the ONLY channel by which a tenant is ever told they still
 *     owe one. Absent from the API, an app-only tenant could not be told at all.
 *   - **`rentable_items`.** The API sent a COUNT (`parking_spots`) and nothing else, while the
 *     invoice carries a "Parking & rentable items" line. Which bay, at what rate, is the most
 *     common billing query there is.
 *   - **`units` / `total_area_sqm`.** `unit` is `belongsTo` — the MASTER only — so a lease over two
 *     shops showed one, and `unit.area_sqm` was the master's area while the rent on the same card
 *     had been priced on the COMBINED area (`deriveBaseRentFromRate()` = rate × `totalAreaSqmOn()`).
 *     Two figures on one screen that could not reconcile.
 *
 * `unit` is kept, unchanged, as the master — a released client reads it.
 *
 * @mixin Lease
 */
class LeaseResource extends JsonResource
{
    /**
     * The rentable items this lease still HOLDS.
     *
     * A release is recorded by closing the holding (`effective_to`), never by detaching it — so
     * "what is the tenant paying for" and "what has this lease ever held" are different questions,
     * and only the first belongs on a tenant's own lease card. Named once because the count and the
     * detail must answer it identically; they did not, and a released bay showed as
     * `parking_spots: 1` beside an empty `rentable_items`.
     *
     * @return \Illuminate\Support\Collection<int, RentableItem>
     */
    private function openHoldings(): Collection
    {
        return $this->rentableItems
            ->filter(fn (RentableItem $item) => $item->getAttribute('pivot')?->effective_to === null)
            ->values();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'reference' => $this->reference,
            'status' => $this->status,
            'commencement_date' => optional($this->commencement_date)->toDateString(),
            'expiry_date' => optional($this->expiry_date)->toDateString(),
            // When RENT starts, which on any fit-out lease is later than the term. A tenant in
            // build-out could not see when they begin paying without ringing the office.
            'rent_commencement_date' => optional($this->rent_commencement_date)->toDateString(),
            'term_months' => $this->term_months,
            'billing_frequency' => $this->billing_frequency,
            'base_rent_monthly' => (float) $this->base_rent_monthly,
            'service_charge_monthly' => (float) $this->service_charge_monthly,
            'total_monthly_amount' => $this->totalMonthlyAmount(),
            'currency' => $this->currency,

            // ---- The deposit, in the three numbers the tenant needs rather than the one they had.
            //
            // The contracted figure alone is unreadable: a tenant who had paid 150,000 of an agreed
            // 180,000 saw "180,000" and could not tell whether that was a bill, a receipt, or a line
            // from their contract. `deposit_outstanding` is the one that matters — nothing else in
            // this system will ever ask them for it.
            'security_deposit' => $this->security_deposit !== null ? (float) $this->security_deposit : null,
            'deposit_held' => $this->depositHeld(),
            'deposit_outstanding' => $this->depositShortfall(),

            // ---- The levy, as a rate they can check against the invoice line.
            'has_marketing_levy' => (bool) $this->has_marketing_levy,
            'marketing_levy_rate' => $this->has_marketing_levy ? (float) $this->marketing_levy_rate : null,

            // ---- Escalation: when the rent steps, and by how much.
            //
            // Present for BOTH shapes. The portal fixed the sibling bug of keying visibility on
            // `escalation_rate > 0`, which is zero on a fixed-AMOUNT lease — so a tenant whose rent
            // stepped by EGP 5,000 a year was shown nothing about it. The client decides what to
            // render from `escalation_type`; the collar is included because a cap the tenant
            // negotiated is worth more to them than the headline rate.
            'escalation_type' => $this->escalation_type,
            'escalation_rate' => $this->escalation_rate !== null ? (float) $this->escalation_rate : null,
            'escalation_amount' => $this->escalation_amount !== null ? (float) $this->escalation_amount : null,
            'escalation_floor_rate' => $this->escalation_floor_rate !== null ? (float) $this->escalation_floor_rate : null,
            'escalation_ceiling_rate' => $this->escalation_ceiling_rate !== null ? (float) $this->escalation_ceiling_rate : null,
            'next_escalation_date' => optional($this->next_escalation_date)->toDateString(),

            // ---- Percentage rent. The RATE alone cannot answer "do I owe anything?" — the
            // threshold is what decides that, and the frequency is whether the breakpoint resets
            // monthly or accumulates over the year.
            'has_percentage_rent' => (bool) $this->has_percentage_rent,
            'percentage_rent_rate' => $this->has_percentage_rent ? (float) $this->percentage_rent_rate : null,
            'percentage_rent_threshold' => $this->has_percentage_rent && $this->percentage_rent_threshold !== null
                ? (float) $this->percentage_rent_threshold
                : null,
            'percentage_rent_frequency' => $this->has_percentage_rent ? $this->percentage_rent_frequency : null,

            // Kept: the COUNT of parking bays, which a released client reads. `rentable_items`
            // below is the detail it never had.
            //
            // **It now asks the same question the detail does, and that is a fix.** It counted
            // every holding ever recorded, released ones included — so a tenant who gave a bay back
            // went on being told they had it. Left alone, the two keys would have contradicted each
            // other in one payload: `parking_spots: 1` beside an empty `rentable_items`.
            'parking_spots' => $this->whenLoaded(
                'rentableItems',
                fn () => $this->openHoldings()
                    ->where('type', RentableItem::TYPE_PARKING)
                    ->count(),
            ),

            // Every bay, store, signage panel and kiosk this lease still holds, with what each one
            // costs. Only OPEN holdings — a released bay is not something the tenant is paying for,
            // and `effective_to` is how a release is recorded.
            'rentable_items' => $this->whenLoaded(
                'rentableItems',
                fn () => $this->openHoldings()
                    ->map(fn (RentableItem $item) => [
                        'id' => (int) $item->id,
                        'code' => $item->code,
                        'type' => $item->type,
                        'monthly_rate' => (float) $item->getAttribute('pivot')->monthly_rate,
                        // The pivot has no cast — `rentable_item_holdings` is a bare `withPivot`,
                        // so this arrives as a raw string. `optional(...)->toDateString()` would
                        // silently answer NULL on it (Optional::__call returns null for a
                        // non-object), which is the shape of bug that ships looking fine.
                        'effective_from' => $item->getAttribute('pivot')->effective_from !== null
                            ? CarbonImmutable::parse($item->getAttribute('pivot')->effective_from)->toDateString()
                            : null,
                    ])
                    ->values(),
            ),

            // The MASTER unit — unchanged, because a released client reads this shape.
            'unit' => $this->whenLoaded('unit', fn () => [
                'id' => $this->unit->id,
                'code' => $this->unit->code,
                // `floors.code` — a STRING like "G" or "1", which is what this field has always
                // carried. `units.floor` was a string column until it was replaced by the Floor
                // register; `$unit->floor` is now the RELATION, so emitting it raw put a whole
                // Floor object on the wire where the mobile client expects a scalar.
                'floor' => $this->unit->floor?->code,
                'category' => $this->unit->category,
                'area_sqm' => (float) $this->unit->area_sqm,
                'asset' => $this->unit->relationLoaded('asset') && $this->unit->asset ? [
                    'id' => $this->unit->asset->id,
                    'name' => $this->unit->asset->name,
                    'code' => $this->unit->asset->code,
                ] : null,
            ]),

            // EVERY unit, master included. A multi-unit lease is ordinary here — `lease_unit` is a
            // DATED pivot, so this is the premises as they stand TODAY, which is what the rent on
            // this card was priced on.
            'units' => $this->whenLoaded(
                'units',
                fn () => $this->unitsOn(now()->toImmutable())
                    ->map(fn (Unit $unit) => [
                        'id' => (int) $unit->id,
                        'code' => $unit->code,
                        'floor' => $unit->floor?->code,
                        'category' => $unit->category,
                        'area_sqm' => (float) $unit->area_sqm,
                        'is_master' => (bool) $unit->getAttribute('pivot')?->is_master,
                    ])
                    ->values(),
            ),

            // The area the rent was actually derived from. Deliberately NOT the sum of `units`
            // above computed client-side: `totalAreaSqm()` is the same method the pricing uses,
            // so the two cannot disagree.
            'total_area_sqm' => $this->whenLoaded('units', fn () => $this->totalAreaSqm()),

            // Whether the operator has uploaded the signed lease. The document itself streams from
            // `GET /me/leases/{id}/document` — a private disk, never a public URL.
            'has_document' => $this->whenLoaded(
                'media',
                fn () => $this->getMedia(Lease::DOCUMENTS_COLLECTION)->isNotEmpty(),
            ),
        ];
    }
}
