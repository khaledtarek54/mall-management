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
        if ($receiverType === 'B' && empty($tenant?->tax_id)) {
            throw new RuntimeException(
                "Tenant '{$tenant?->name}' (id={$tenant?->id}) is a business but has no tax_id — ETA submission requires one. Add the tax registration number on the tenant record before submitting invoice {$invoice->number}."
            );
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
                    'governate' => 'Giza',
                    'regionCity' => '6th of October City',
                    'street' => $tenant?->address ?? 'N/A',
                    'buildingNumber' => '1',
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
        // ETA wants EGS item codes. These are placeholder mappings — replace with
        // the registered EGS codes for each charge type once the taxpayer profile is set.
        return match ($chargeType) {
            'base_rent' => 'EG-6820-001',
            'service_charge' => 'EG-6820-002',
            'utility' => 'EG-3530-001',
            'parking' => 'EG-5221-001',
            'percentage_rent' => 'EG-6820-003',
            default => 'EG-6820-999',
        };
    }

    private function round(float $value): float
    {
        return round($value, 5);
    }
}
