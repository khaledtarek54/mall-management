<?php

namespace App\Filament\Portal\Resources\Invoices\Pages;

use App\Filament\Portal\Actions\InvoiceActions;
use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Models\Invoice;
use App\Services\InvoicePdfService;
use App\Support\Filament\PdfDownloadAction;
use Filament\Resources\Pages\ViewRecord;

class ViewInvoice extends ViewRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            PdfDownloadAction::make('downloadPdf')
                ->label(__('admin.actions.download_pdf'))
                ->service(InvoicePdfService::class)
                ->recipient(fn (Invoice $record) => $record->tenant),
            // ONE definition for the page and the list row — see the registry for why.
            ...InvoiceActions::all(),
        ];
    }
}
