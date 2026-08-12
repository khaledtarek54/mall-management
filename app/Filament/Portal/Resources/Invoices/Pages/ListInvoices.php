<?php

namespace App\Filament\Portal\Resources\Invoices\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Portal\Resources\Invoices\InvoiceResource;
use App\Models\Tenant;
use App\Services\TenantStatementPdfService;
use App\Support\Portal;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            Action::make('downloadStatement')
                ->label(__('admin.statement.action_label'))
                ->icon('heroicon-o-document-arrow-down')
                ->color('primary')
                ->action(function () {
                    /** @var Tenant $tenant */
                    $tenant = Portal::tenant();
                    $svc = app(TenantStatementPdfService::class);
                    $pdf = $svc->build($tenant);

                    return response()->streamDownload(
                        fn () => print ($pdf),
                        $svc->filename($tenant),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
        ];
    }
}
