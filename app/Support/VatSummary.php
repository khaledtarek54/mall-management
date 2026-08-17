<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Taxable value and tax, grouped by the rate each line was billed at.
 *
 * A tax document needs the split, not one VAT total: base rent is VAT-exempt while service charge is
 * standard-rated, so a single Atriom document routinely carries both, and the counterparty's
 * accountant has to know which part of it carries claimable input tax. "VAT: 1,400" against a
 * 20,000 subtotal tells them nothing they can act on.
 *
 * **Shared, because there is more than one tax document.** This lived as a private static on
 * `InvoicePdfService` from 2026-08-12 and the credit note — which REVERSES a tax invoice, and which
 * the tenant needs in order to reverse the input VAT they already claimed — had no summary at all.
 * One implementation is what stops the pair drifting again the next time the rule changes.
 *
 * Read off the LINES, which hold the rate they were issued at, and never recomputed from today's
 * catalogue. VAT here is origination-only: a document keeps the rate it was billed at, so
 * re-deriving the summary through `Vat::rateForType()` would silently restate every historical
 * document the day a rate rise takes effect — see the VAT invariant in CLAUDE.md.
 */
final class VatSummary
{
    /**
     * @param  iterable<object{amount: mixed, vat_rate: mixed, vat_amount: mixed}>  $items
     *                                                                                      Invoice items or credit-note items — the two share this shape, and the summary
     *                                                                                      reads nothing else off them.
     * @return list<array{rate: float, base: float, vat: float}> Highest rate first.
     */
    public static function forItems(iterable $items): array
    {
        return Collection::make($items)
            ->groupBy(fn (object $item): string => (string) round((float) $item->vat_rate, 2))
            ->map(fn (Collection $group, string $rate): array => [
                'rate' => (float) $rate,
                'base' => round((float) $group->sum(fn (object $i) => (float) $i->amount), 2),
                'vat' => round((float) $group->sum(fn (object $i) => (float) $i->vat_amount), 2),
            ])
            ->sortByDesc('rate')
            ->values()
            ->all();
    }

    /**
     * Whether the summary says anything the totals block does not.
     *
     * With a single rate it does not — the document's own subtotal/VAT/total lines already state it,
     * and a one-row summary table is noise on every ordinary rent-only invoice.
     *
     * @param  list<array{rate: float, base: float, vat: float}>  $summary
     */
    public static function worthPrinting(array $summary): bool
    {
        return count($summary) > 1;
    }
}
