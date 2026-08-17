<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Models\Lease;
use Filament\Resources\Pages\CreateRecord;

class CreateInvoice extends CreateRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // The invoice's property is derived from its lease — re-validate the
        // submitted lease is within the user's visible set (property isolation).
        InvoiceResource::assertLeaseAssetInScope($data['lease_id'] ?? null);

        // The debtor is DERIVED here, not trusted. The form shows it read-only beside the lease
        // picker, and `afterStateUpdated` fills it — but a disabled field's value still arrives in
        // the Livewire payload, so a crafted request could name a different party and bill someone
        // who never agreed to the charge.
        //
        // A derivation rather than a refusal, and only on CREATE, because a mismatch is legitimate
        // elsewhere and this must not pretend otherwise: `IssueInvoiceService` takes an explicit
        // `$tenantId` so a violation fine, a bounced-cheque fee and a late fee carry the debtor
        // stated on their SOURCE document, and a draft invoice may be freely re-homed to another
        // lease before it is issued. Both are deliberate; a model-level equality rule broke both,
        // which is how this landed here instead.
        if (filled($data['lease_id'] ?? null)) {
            $data['tenant_id'] = Lease::whereKey($data['lease_id'])->value('tenant_id') ?? $data['tenant_id'] ?? null;
        }

        return $data;
    }
}
