<?php

namespace App\Filament\Owner\Resources\Invoices\Tables;

use App\Services\InvoicePdfService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class InvoicesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.invoice.number'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable(),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->searchable()
                    ->weight('medium'),
                TextColumn::make('lease.unit.asset.name')
                    ->label(__('admin.tables.asset.name'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('period_start')
                    ->label(__('admin.tables.invoice.period'))
                    ->formatStateUsing(fn ($state) => $state->isoFormat('MMM YYYY'))
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('admin.tables.invoice.total'))
                    ->money('EGP', divideBy: 1)
                    ->sortable()
                    ->weight('semibold'),
                TextColumn::make('balance')
                    ->label(__('admin.tables.invoice.balance'))
                    ->money('EGP', divideBy: 1)
                    ->color(fn ($state) => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.invoice.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'partially_paid' => 'info',
                        'issued' => 'warning',
                        'overdue' => 'danger',
                        'cancelled' => 'gray',
                        default => 'gray',
                    }),
                TextColumn::make('issue_date')
                    ->label(__('admin.fields.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.invoice')),
            ])
            ->defaultSort('issue_date', 'desc')
            ->recordActions([
                ViewAction::make(),
                Action::make('downloadPdf')
                    ->label(__('admin.actions.pdf'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->action(function ($record) {
                        $svc = app(InvoicePdfService::class);
                        $pdf = $svc->build($record);
                        return response()->streamDownload(
                            fn () => print($pdf),
                            $svc->filename($record),
                            ['Content-Type' => 'application/pdf'],
                        );
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('downloadPdfBundle')
                        ->label(__('admin.actions.bulk_download_pdfs'))
                        ->icon('heroicon-o-archive-box-arrow-down')
                        ->color('gray')
                        ->action(function ($records) {
                            $svc = app(InvoicePdfService::class);
                            $tmp = tempnam(sys_get_temp_dir(), 'invoices_').'.zip';
                            $zip = new \ZipArchive;
                            $zip->open($tmp, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
                            foreach ($records as $invoice) {
                                $zip->addFromString($svc->filename($invoice), $svc->build($invoice));
                            }
                            $zip->close();
                            return response()->download($tmp, 'invoices-'.now()->format('Ymd-His').'.zip')->deleteFileAfterSend();
                        }),
                ]),
            ]);
    }
}
