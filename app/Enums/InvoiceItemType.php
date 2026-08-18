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
    case CamRecovery = 'cam_recovery';
    case CamAdminFee = 'cam_admin_fee';
    case ViolationFine = 'violation_fine';

    /**
     * A security deposit BILLED to the tenant — Voyager's model, adopted 2026-08-18.
     *
     * It is not revenue and never was: its charge code posts to `deposits_held`, a LIABILITY, so
     * billing one is `Dr AR / Cr Deposits Held` and the tenant's payment is `Dr Bank / Cr AR`. The
     * pair nets to the cash-and-liability entry a direct receipt posts in one step.
     *
     * Before this the deposit existed ONLY as a `DepositTransaction` an operator recorded after the
     * money arrived, so nothing ever asked the tenant to pay it — the portal had to tell them to go
     * and make a bank transfer. Now it ages, chases and settles like any other charge.
     */
    case SecurityDeposit = 'security_deposit';

    /** A returned-cheque handling fee — Voyager posts one; see module 33. */
    case NsfFee = 'nsf_fee';
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
