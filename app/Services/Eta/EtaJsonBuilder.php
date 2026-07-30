<?php

namespace App\Services\Eta;

use App\Models\Invoice;
use RuntimeException;

/**
 * Builds the JSON document ETA expects for B2B invoice submission.
 *
 * Spec reference: https://sdk.invoicing.eta.gov.eg/document-package-format/
 * The shape mirrors the v1.0 invoice schema — issuer + receiver + lines +
 * tax breakdown + totals. We keep the field set to what ETA actually
 * validates, not the full optional surface.
 */
class EtaJsonBuilder
{
    public function build(Invoice $invoice): array
    {
        $invoice->loadMissing(['lease.tenant', 'items.charge']);

        $issuer = config('eta.issuer');
        $tenant = $invoice->lease?->tenant;
        $receiverType = $this->mapTenantType($tenant?->type);

        // Business receivers must carry a tax_id — ETA validates this at
        // submission time and rejects the document otherwise. Catch it here
        // so the operator sees a clear "tenant X is missing a tax ID"
        // error instead of an opaque ETA rejection later.
        // No buyer, no document. `invoices.tenant_id` is NOT NULL, but Tenant soft-deletes
        // and the relation applies that scope — so an invoice whose tenant was archived
        // resolves $tenant to null. The old code filed it anyway as "Unknown" with tax id
        // 000000000 and the hardcoded Giza address: a document naming a buyer that does
        // not exist, which ETA would reject and which nobody could reconcile if it didn't.
        if ($tenant === null) {
            throw new RuntimeException(
                "Invoice {$invoice->number} has no tenant on record (archived?) — ETA files the buyer's identity and address, so there is nothing to submit. Restore the tenant before submitting."
            );
        }

        if ($receiverType === 'B' && empty($tenant?->tax_id)) {
            throw new RuntimeException(
                "Tenant '{$tenant?->name}' (id={$tenant?->id}) is a business but has no tax_id — ETA submission requires one. Add the tax registration number on the tenant record before submitting invoice {$invoice->number}."
            );
        }

        // Same reasoning, one field later. The receiver address used to be four
        // CONSTANTS — 'Giza', '6th of October City', building '1', with the tenant's
        // whole freeform address dropped into `street`. Every document filed for a
        // tenant outside 6th of October therefore declared the wrong buyer address to
        // the tax authority, and the building number was wrong for all of them. Mock
        // mode hid it: the fake endpoint accepts anything, so nothing ever bounced.
        //
        // Refuse rather than guess. The alternative — parsing the freeform address
        // into parts — puts invented data on a legal document, which is worse than
        // the constants it replaces. A refusal names the tenant to fix; the operator
        // fills four fields once.
        //
        // Businesses only, matching the tax_id guard above: a `P` (person) receiver is
        // not subject to the same address validation, and individual tenants are not
        // required to be filed at all.
        if ($receiverType === 'B') {
            $missing = array_keys(array_filter([
                'governorate' => blank($tenant?->address_governorate),
                'city' => blank($tenant?->address_city),
                'street' => blank($tenant?->address_street),
                'building number' => blank($tenant?->address_building_number),
            ]));

            if ($missing !== []) {
                throw new RuntimeException(
                    "Tenant '{$tenant?->name}' (id={$tenant?->id}) is missing its ".implode(', ', $missing)
                    ." — ETA files the buyer's address in parts and validates them. Complete the address on the tenant record before submitting invoice {$invoice->number}."
                );
            }
        }

        return [
            'issuer' => [
                'address' => $issuer['address'],
                'type' => $issuer['type'],
                'id' => $issuer['tax_registration_number'],
                'name' => $issuer['name'],
            ],
            'receiver' => [
                'address' => [
                    'country' => 'EG',
                    // ETA's own spelling of the key is 'governate'. Keep it — it is their
                    // wire format, not ours to correct.
                    'governate' => $tenant->address_governorate,
                    'regionCity' => $tenant->address_city,
                    // A PERSON receiver is not guarded above and is not address-validated
                    // by ETA, so it may have no structured street — fall back to the
                    // freeform address rather than sending an empty string.
                    'street' => $tenant->address_street ?: ($tenant->address ?? 'N/A'),
                    'buildingNumber' => $tenant->address_building_number,
                ],
                'type' => $receiverType,
                'id' => $tenant?->tax_id ?? '000000000',
                'name' => $tenant?->legal_name ?? $tenant?->name ?? 'Unknown',
            ],
            'documentType' => 'i',
            'documentTypeVersion' => '1.0',
            'dateTimeIssued' => $invoice->issue_date?->toIso8601String(),
            'taxpayerActivityCode' => '6820', // Renting and operating of own or leased real estate
            'internalID' => $invoice->number,
            'invoiceLines' => $this->buildLines($invoice),
            'totalDiscountAmount' => 0.0,
            'totalSalesAmount' => $this->round((float) $invoice->subtotal),
            'netAmount' => $this->round((float) $invoice->subtotal),
            'taxTotals' => $this->buildTaxTotals($invoice),
            'totalAmount' => $this->round((float) $invoice->total),
            'extraDiscountAmount' => 0.0,
            'totalItemsDiscountAmount' => 0.0,
        ];
    }

    private function buildLines(Invoice $invoice): array
    {
        return $invoice->items->map(function ($item) {
            $amount = (float) $item->amount;
            $vatRate = (float) ($item->vat_rate ?? 0);
            $vatAmount = (float) ($item->vat_amount ?? 0);

            return [
                'description' => $item->description,
                'itemType' => 'EGS', // Egyptian Goods/Services classification
                'itemCode' => $this->mapItemCode($item->charge?->type),
                'unitType' => 'EA',
                'quantity' => 1.0,
                'internalCode' => $item->charge?->type ?? 'other',
                'salesTotal' => $this->round($amount),
                'total' => $this->round($amount + $vatAmount),
                'valueDifference' => 0.0,
                'totalTaxableFees' => 0.0,
                'netTotal' => $this->round($amount),
                'itemsDiscount' => 0.0,
                'unitValue' => [
                    'currencySold' => 'EGP',
                    'amountEGP' => $this->round($amount),
                ],
                'discount' => ['rate' => 0.0, 'amount' => 0.0],
                'taxableItems' => $vatRate > 0 ? [[
                    'taxType' => 'T1',
                    'amount' => $this->round($vatAmount),
                    'subType' => 'V009',
                    'rate' => $vatRate,
                ]] : [],
            ];
        })->values()->all();
    }

    private function buildTaxTotals(Invoice $invoice): array
    {
        $vatTotal = (float) $invoice->vat_amount;
        if ($vatTotal <= 0) {
            return [];
        }

        return [[
            'taxType' => 'T1',
            'amount' => $this->round($vatTotal),
        ]];
    }

    private function mapTenantType(?string $type): string
    {
        // ETA: B = business, P = person, F = foreigner
        return match ($type) {
            'individual' => 'P',
            'foreign' => 'F',
            default => 'B',
        };
    }

    private function mapItemCode(?string $chargeType): string
    {
        // EGS item codes are config-driven (config/eta.php → env) so the operator's
        // real registered codes drop in without a code change. See config 'egs_codes'.
        $codes = (array) config('eta.egs_codes', []);

        return $codes[$chargeType] ?? $codes['default'] ?? 'EG-6820-999';
    }

    private function round(float $value): float
    {
        return round($value, 5);
    }
}
