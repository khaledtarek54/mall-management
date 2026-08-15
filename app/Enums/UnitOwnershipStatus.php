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
}
