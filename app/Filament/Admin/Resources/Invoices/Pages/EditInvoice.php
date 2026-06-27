<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Services\InvoicePdfService;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Resources\Pages\EditRecord;

class EditInvoice extends EditRecord
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('downloadPdf')
                ->label(__('admin.actions.download_pdf'))
                ->icon('heroicon-o-arrow-down-tray')
                ->color('gray')
                ->action(function () {
                    $svc = app(InvoicePdfService::class);
                    $pdf = $svc->build($this->record);
                    return response()->streamDownload(
                        fn () => print($pdf),
                        $svc->filename($this->record),
                        ['Content-Type' => 'application/pdf'],
                    );
                }),
            Action::make('paymentLink')
                ->label(__('admin.actions.payment_link'))
                ->icon('heroicon-o-link')
                ->color('gray')
                ->visible(fn () => config('integrations.paymob.enabled') && $this->record->isPayable())
                ->modalHeading(fn () => __('admin.actions.payment_link').' · '.$this->record->number)
                ->modalSubmitAction(false)
                ->modalContent(fn () => new \Illuminate\Support\HtmlString(
                    '<p style="margin-bottom:.5rem;font-size:.875rem;color:#6b7280;">'.e(__('admin.actions.payment_link_hint')).'</p>'
                    .'<input readonly onclick="this.select()" value="'.e($this->record->paymentLinkUrl()).'" '
                    .'style="width:100%;padding:.6rem .75rem;border:1px solid #d1d5db;border-radius:.5rem;font-size:.8125rem;" />'
                )),
            DeleteAction::make(),
            ForceDeleteAction::make(),
            RestoreAction::make(),
        ];
    }
}
