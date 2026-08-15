<?php

namespace App\Enums;

/**
 * What the buyer actually bought — plan 08 §8 Q1, carried as data because the operator cannot yet
 * answer it.
 *
 * **Billing is identical across all three.** They differ in law and in how the tenure ends, and both
 * of those are already carried by `started_at`/`ended_at`. Nothing downstream branches on this: it
 * is recorded because a mall selling حق انتفاع and a mall selling تمليك are answering different
 * questions to a lawyer, and the register has to be able to say which.
 *
 * If that ever stops being true — if a usufruct must bill differently from a freehold — this is the
 * field to branch on, and the branch belongs in the service, not in a second table.
 */
enum UnitTenureType: string
{
    /** Freehold sale — تمليك. Perpetual; ends only by resale. */
    case Freehold = 'freehold';

    /**
     * Usufruct — حق انتفاع. A right to use for a long fixed term, common in Egyptian schemes where
     * the land title cannot pass. Legally a lease; sold, priced and serviced like ownership, which
     * is why it lives here rather than in `leases`.
     */
    case Usufruct = 'usufruct';

    /** A long leasehold sold as a unit — the hybrid some developers use. */
    case LeaseholdSale = 'leasehold_sale';

    public static function default(): self
    {
        return self::Freehold;
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
        return __("admin.enums.unit_tenure_type.{$this->value}");
    }

    /**
     * Does this tenure necessarily end on a date?
     *
     * A freehold does not, so an open `ended_at` is normal and the form must not demand one. The
     * other two do, and a register that cannot say when the right expires is a register that cannot
     * warn anybody it is about to.
     */
    public function isTermed(): bool
    {
        return $this !== self::Freehold;
    }
}
