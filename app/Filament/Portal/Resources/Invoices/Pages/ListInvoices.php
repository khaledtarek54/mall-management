<?php

namespace App\Filament\Portal\Resources\Invoices\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Models\Tenant;
use App\Services\TenantStatementPdfService;
use App\Support\Filament\PdfDownloadAction;
use App\Support\Portal;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Icons\Heroicon;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            // No record: this is the signed-in tenant's own statement, so the recipient IS the
            // portal tenant and the picker opens on the language they set for themselves.
            PdfDownloadAction::make('downloadStatement')
                ->label(__('admin.statement.action_label'))
                ->icon(Heroicon::OutlinedDocumentArrowDown)
                ->color('primary')
                ->recipient(fn () => Portal::tenant())
                ->document(fn (mixed $record, string $locale): string => app(TenantStatementPdfService::class)
                    ->build(Portal::tenant(), null, null, null, $locale))
                ->filename(fn (): string => app(TenantStatementPdfService::class)->filename(Portal::tenant())),
        ];
    }
}
