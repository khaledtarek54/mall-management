<?php

namespace App\Enums;

/**
 * Where a sale has got to. Distinct from `UnitManagementMode`, which says what the owner is doing
 * with the unit once he has it — a `HandedOver` unit can be occupied, let or empty.
 */
enum UnitOwnershipStatus: string
{
    /** Reserved against a deposit; not yet contracted. Bills nothing. */
    case Reserved = 'reserved';

    /** Contract signed, unit not yet handed over. Bills nothing — the operator still holds it. */
    case Contracted = 'contracted';

    /**
     * Handed over. **This is the state that bills**: the owner has the keys, so the service charge
     * runs from here whether or not anybody is trading.
     */
    case HandedOver = 'handed_over';

    /** Sold on. Terminal, and reached by the transfer workflow rather than by editing a row. */
    case Transferred = 'transferred';

    public static function default(): self
    {
        return self::Contracted;
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
        return __("admin.enums.unit_ownership_status.{$this->value}");
    }

    /**
     * Does an ownership in this state owe the service charge?
     *
     * Handover is the trigger, not contract signature — the operator is still carrying the unit's
     * cost until the keys change hands. Named once so the billing sweep and the register agree.
     */
    public function isBillable(): bool
    {
        return $this === self::HandedOver;
    }

    /** Terminal states cannot be edited back — the transfer is the document. */
    public function isTerminal(): bool
    {
        return $this === self::Transferred;
    }

    /**
     * Did this ownership ever take possession — i.e. can it owe common cost for a PAST period?
     *
     * The twin of {@see isBillable()}, and the difference between them is a period. `isBillable()`
     * asks *"does this owe the assessment NOW"*, which only a live `handed_over` does. A CAM
     * reconciliation asks about a year that has already run, and a unit **sold on** was owned for
     * part of it — `Transferred` is a terminal state, not a statement that the owner never had the
     * keys.
     *
     * Reading `isBillable()` there excluded the seller from every resale (SW-220), which is the same
     * shape the lease branch of `CamReconciliationService::participants()` avoids by excluding the
     * states that never occupied anything (`draft`, `pending_approval`, `cancelled`) rather than
     * requiring `active`.
     *
     * @return list<string>
     */
    public static function everHadPossession(): array
    {
        return [self::HandedOver->value, self::Transferred->value];
    }
}
