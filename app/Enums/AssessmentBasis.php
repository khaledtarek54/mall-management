<?php

namespace App\Enums;

/**
 * How an owner's share of the common cost is worked out — plan 08 §8 Q2 as a row.
 *
 * Deliberately the same shape as `CamExpensePool::denominator_basis`: the apportionment METHOD is a
 * field on the row, not a branch in the service. That is how Yardi is configurable — you add a row,
 * you do not edit a code path — and this codebase already proved the pattern works on CAM.
 *
 * **`Area` is the default because it is today's behaviour**, so a building with both leased and sold
 * units reconciles exactly the way it already does until somebody deliberately says otherwise.
 */
enum AssessmentBasis: string
{
    /** Share of floor area — what every CAM pool does today. */
    case Area = 'area';

    /**
     * The participation interest stated in the deed — Yardi's native condo basis, a percentage
     * carried per unit that sums to 100 across the building. Reads `participation_pct`; a null
     * there falls back to area, so an unconfigured deed is never a zero share.
     */
    case Participation = 'participation';

    /** Share of purchase price — used where the developer's contract set صيانة against the price. */
    case PurchaseValue = 'purchase_value';

    /** A percentage the parties simply agreed. No denominator can produce a negotiated number. */
    case Stated = 'stated';

    public static function default(): self
    {
        return self::Area;
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** @return array<string, string> */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn (self $c) => [$c->value => $c->label()])->all();
    }

    public function label(): string
    {
        return __("admin.enums.assessment_basis.{$this->value}");
    }

    /**
     * The ownership column this basis reads, or null when it derives from the unit instead.
     *
     * Stated here rather than in the allocator so the FORM can require the right field: a basis that
     * needs a number nobody typed is the inert-configuration bug this codebase has already been
     * bitten by — saved, visible, and consulted by nothing.
     */
    public function requiredColumn(): ?string
    {
        return match ($this) {
            self::Participation, self::Stated => 'participation_pct',
            self::PurchaseValue => 'purchase_price',
            self::Area => null,
        };
    }
}
