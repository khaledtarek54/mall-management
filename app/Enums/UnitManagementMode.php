<?php

namespace App\Enums;

/**
 * What the owner is doing with the unit — the four states of plan 08 §6, and **each is a different
 * money flow**. This is the field that decides who bills whom; `UnitTenureType` decides nothing.
 *
 * Every one of the four owes the service charge. What varies is the rent: whether it exists, who
 * collects it, and whether the operator ends up owing the owner a net.
 */
enum UnitManagementMode: string
{
    /** مالك شاغل — he trades from it himself. No rent, no lease, still bound by every mall rule. */
    case SelfOccupied = 'self_occupied';

    /**
     * مالك مؤجر بنفسه — he let it himself. The tenant is real for occupancy, violations, SLA and
     * fit-out, but the operator takes no rent, so the lease raises no rent invoice.
     */
    case SelfLet = 'self_let';

    /** مالك في pool الإدارة — the operator lets it for him, keeps a fee, remits the net. */
    case OperatorManaged = 'operator_managed';

    /**
     * وحدة فاضية مملوكة — owned and empty. Still owes the service charge, and with no tenant and no
     * rent to withhold it is the hardest line in the building to collect.
     */
    case Vacant = 'vacant';

    /** A newly-recorded sale is empty until the operator says otherwise. */
    public static function default(): self
    {
        return self::Vacant;
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
        return __("admin.enums.unit_management_mode.{$this->value}");
    }

    /**
     * Does the operator collect this unit's rent, and therefore owe the owner a net?
     *
     * The one predicate the agency phase turns on. Named once here so the statement run, the fee
     * calculation and the remittance cannot drift on what "managed" means.
     */
    public function operatorCollectsRent(): bool
    {
        return $this === self::OperatorManaged;
    }

    /** Is there a tenant trading in this unit at all — under anyone's lease? */
    public function isLet(): bool
    {
        return $this === self::SelfLet || $this === self::OperatorManaged;
    }
}
