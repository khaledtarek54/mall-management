<?php

namespace App\Support;

use App\Models\TaxCode;
use Illuminate\Support\Facades\Auth;

/**
 * The server-side half of the tax-rate gate: a line lands at the catalogue's rate unless the
 * operator holds the right to depart from it.
 *
 * The invoice and credit-note forms render `vat_rate` read-only without `tax_codes.override`.
 * **That is a UI gate and nothing more.** A `readOnly()` field is still hydrated and still
 * dehydrated, so a crafted Livewire payload sets it exactly as if the box had been editable — the
 * same class of hole this codebase already documents for `visible()`, which states an intent rather
 * than performing a refusal.
 *
 * So the rate is re-derived here, from the repeater's own save hooks
 * (`mutateRelationshipDataBeforeCreateUsing` / `…BeforeSaveUsing`), which is the only layer that
 * sees a line at all: the items repeater is relationship-backed, so the rows never pass through the
 * page's `mutateFormDataBeforeCreate` — an enforcement written there compiles, reads correctly, and
 * protects nothing.
 *
 * **It corrects rather than refuses.** A refusal would be the wrong shape: an operator without the
 * right did not ask for the rate they submitted — the form showed them the catalogue's figure and
 * their browser sent it back — so there is nothing for them to fix and an error would be a puzzle.
 * Writing the correct rate gives them what they saw and what they intended. Someone deliberately
 * forging a payload gets the catalogue's rate too, which is the point.
 *
 * **A line with no tax code is left alone.** It is unclassified, {@see Vat}'s floor already decides
 * what it bills, and there is no catalogue figure to correct it towards.
 */
class CatalogueTaxRate
{
    /** The permission that allows a document line to carry a rate the catalogue did not produce. */
    public const OVERRIDE_PERMISSION = 'tax_codes.override';

    /**
     * How far a PURCHASE document's tax may sit from the derived figure before someone must say why.
     *
     * One pound. A rounding difference between two systems computing the same percentage on the same
     * base is sub-unit — even across a multi-line supplier document it lands in piastres. Anything
     * larger is a different rate or a different base, which is a decision rather than arithmetic, and
     * decisions on a filed return are worth a sentence.
     */
    public const PURCHASE_TOLERANCE = 1.00;

    public static function mayOverride(): bool
    {
        return Auth::user()?->can(self::OVERRIDE_PERMISSION) ?? false;
    }

    /**
     * The tax a purchase document's net amount attracts under `$taxCode`, on `$on`.
     *
     * Null when the code is unknown or carries no rate — the caller leaves the operator's figure
     * alone rather than replacing it with a zero it cannot justify.
     */
    public static function deriveOnNet(?string $taxCode, float $net, ?string $on = null): ?float
    {
        if (! is_string($taxCode) || $taxCode === '') {
            return null;
        }

        $rate = TaxCode::rateOn($taxCode, $on !== null && $on !== '' ? $on : null);

        return $rate === null ? null : round($net * max(0.0, $rate) / 100, 2);
    }

    /**
     * Does a purchase document's tax depart from what its code implies by more than rounding?
     *
     * **Deliberately gentler than the sales side.** On an invoice the rate is our decision, so
     * {@see enforce()} re-derives it and an operator without the override right cannot land anything
     * else. On a supplier's bill the tax is *their* number on *their* document: a system that
     * refused to record what a supplier actually charged would be wrong, and the operator would
     * enter the difference somewhere worse. So the amount stays editable and a real departure asks
     * for a reason instead — which is what Odoo and SAP do, and for the same reason.
     */
    public static function purchaseTaxDeparts(?string $taxCode, float $net, float $tax, ?string $on = null): bool
    {
        $derived = self::deriveOnNet($taxCode, $net, $on);

        return $derived !== null && abs($derived - $tax) > self::PURCHASE_TOLERANCE;
    }

    /**
     * Re-derive one line's rate from its tax code, for the document's date.
     *
     * @param  array<string, mixed>  $item
     * @param  string|null  $on  the parent document's date — a rate is resolved for the document,
     *                           never for today, so a back-dated invoice bills the regime that was
     *                           in force when it was raised
     * @return array<string, mixed>
     */
    public static function enforce(array $item, ?string $on = null): array
    {
        $taxCode = $item['tax_code'] ?? null;

        if (! is_string($taxCode) || $taxCode === '') {
            // Unclassified: nothing to enforce against. Clear any reason that arrived with it — an
            // override reason on a line that overrides nothing is a claim about a decision nobody
            // made, on a document an auditor reads.
            $item['tax_override_reason'] = null;

            return $item;
        }

        $resolved = TaxCode::rateOn($taxCode, $on !== null && $on !== '' ? $on : null);

        if ($resolved === null) {
            return $item;
        }

        $resolved = max(0.0, $resolved);

        if (! self::mayOverride()) {
            $item['vat_rate'] = $resolved;
            $item['tax_override_reason'] = null;

            return $item;
        }

        // They may override — but a reason is only meaningful while there IS a departure. One left
        // behind after the rate was put back would read as an override that is no longer there.
        if (abs($resolved - (float) ($item['vat_rate'] ?? 0)) < 0.005) {
            $item['tax_override_reason'] = null;
        }

        return $item;
    }
}
