<?php

namespace App\Enums;

/**
 * Allowed invoice line-item types — the single source of truth (model-level,
 * not a DB enum). Drives validation + Filament options + maps to the
 * admin.enums.invoice_item_type.* translation keys.
 *
 * Add a new type here — no migration needed (invoice_items.type is a string).
 */
enum InvoiceItemType: string
{
    case BaseRent = 'base_rent';
    case ServiceCharge = 'service_charge';
    case Utility = 'utility';
    case Parking = 'parking';
    case PercentageRent = 'percentage_rent';
    case Marketing = 'marketing';
    case LateFee = 'late_fee';
    case Other = 'other';

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Localized value => label map for Filament selects + table formatting. */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $c) => [$c->value => __("admin.enums.invoice_item_type.{$c->value}")])
            ->all();
    }
}
