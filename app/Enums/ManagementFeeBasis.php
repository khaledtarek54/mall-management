<?php

namespace App\Enums;

/**
 * What the operator's management fee is a percentage OF — plan 08 §8 Q4 as a row.
 *
 * Defaults to `Collected`, which is Yardi's default and the defensible one: a fee on BILLED rent
 * pays the operator for money it has not got, and then has to be clawed back when the tenant does
 * not pay. Collected is also what makes the unit-owner statement cash-basis, unlike the accrual
 * property-owner statement in module 32 — a difference worth stating twice, because getting it wrong
 * remits money that never arrived.
 */
enum ManagementFeeBasis: string
{
    /** A share of rent actually received in the period. */
    case Collected = 'collected';

    /** A share of rent invoiced in the period, paid or not. */
    case Billed = 'billed';

    public static function default(): self
    {
        return self::Collected;
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
        return __("admin.enums.management_fee_basis.{$this->value}");
    }
}
