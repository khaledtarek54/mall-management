<?php

namespace App\Enums;

/**
 * Which kind of party a `tenants` row is.
 *
 * **`tenants` is the AR PARTY table.** It holds whoever can owe the operator money — the retailer who
 * leases a shop and the buyer who bought one. That is Yardi's own answer: its ledger belongs to a
 * customer record, and in the condo product the unit owner simply IS that record type. It is what
 * lets payments, credit notes, deposits, cheques, ageing, the portal and the mobile API serve an
 * owner without any of them learning that owners exist.
 *
 * The word "tenant" therefore means *counterparty* in the schema and *retailer* on screen — the UI
 * never shows it to an owner. Model-level, not a DB enum: adding a party kind needs no migration.
 *
 * @see docs/plans/08-unit-owners.md §4
 */
enum PartyType: string
{
    /** A retailer who leases space. Every row before unit owners existed is one. */
    case Retailer = 'retailer';

    /** A buyer who owns a unit — مالك وحدة. Pays no rent; owes the service charge. */
    case UnitOwner = 'unit_owner';

    /** What an unqualified row is, so the column needed no backfill. */
    public static function default(): self
    {
        return self::Retailer;
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
        return __("admin.enums.party_type.{$this->value}");
    }

    /**
     * Does this party sign leases and declare sales?
     *
     * A unit owner does neither — the portal hides both, and the leasing screens never offer them
     * as a counterparty. Asked as a question about the party rather than compared against a literal
     * at each call site, so a third party kind cannot be forgotten at one of them.
     */
    public function leasesSpace(): bool
    {
        return $this === self::Retailer;
    }
}
