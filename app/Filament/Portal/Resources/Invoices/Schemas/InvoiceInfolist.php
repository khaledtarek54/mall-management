<?php

namespace App\Filament\Portal\Resources\Invoices\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class InvoiceInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.invoice'))
                ->columns(3)
                ->components([
                    TextEntry::make('number')
                        ->label(__('admin.fields.invoice_number'))
                        ->copyable()
                        ->fontFamily('mono'),
                    TextEntry::make('lease.unit.code')
                        ->label(__('admin.fields.unit_label'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.statuses.invoice.{$state}"))
                        ->color(fn (string $state): string => match ($state) {
                            'paid' => 'success',
                            'partially_paid' => 'warning',
                            'overdue' => 'danger',
                            'issued' => 'info',
                            default => 'gray',
                        }),
                    TextEntry::make('issue_date')
                        ->label(__('admin.fields.issue_date'))
                        ->date('d/m/Y'),
                    TextEntry::make('due_date')
                        ->label(__('admin.fields.due_date'))
                        ->date('d/m/Y'),
                    TextEntry::make('period_start')
                        ->label(__('admin.fields.period'))
                        ->formatStateUsing(fn ($record) => $record->period_start?->locale(app()->getLocale())->isoFormat('MMM YYYY') ?? '—'),
                ]),
            Section::make(__('admin.sections.amounts'))
                ->columns(4)
                ->components([
                    TextEntry::make('subtotal')->label(__('admin.fields.subtotal'))->money('EGP'),
                    TextEntry::make('vat_amount')->label(__('admin.fields.vat_amount'))->money('EGP'),
                    TextEntry::make('total')->label(__('admin.fields.total'))->money('EGP')->weight('bold'),
                    TextEntry::make('balance')
                        ->label(__('admin.fields.balance'))
                        ->money('EGP')
                        ->color(fn ($state) => $state > 0 ? 'danger' : 'success')
                        ->weight('bold'),
                ]),
            // ── The lines, and which of them are under dispute (MF-07) ────────────────────────
            // The portal showed totals only, so a tenant who had formally disputed the service
            // charge saw the same "EGP 41,400 overdue" as one who had disputed nothing — and no
            // acknowledgement anywhere that the argument had been recorded at all.
            Section::make(__('admin.sections.invoice_items'))
                ->components([
                    RepeatableEntry::make('items')
                        ->hiddenLabel()
                        ->columns(3)
                        ->components([
                            TextEntry::make('description')
                                ->label(__('admin.fields.description'))
                                ->columnSpan(2),
                            TextEntry::make('total')
                                ->label(__('admin.fields.total'))
                                ->money('EGP')
                                ->alignEnd(),
                            TextEntry::make('disputed_reason')
                                ->label(__('admin.reports.disputed'))
                                ->badge()
                                ->color('warning')
                                ->icon('heroicon-o-exclamation-triangle')
                                ->columnSpanFull()
                                ->visible(fn ($record): bool => $record->isDisputed()),
                        ]),
                ]),
            Section::make(__('admin.sections.notes'))
                ->visible(fn ($record) => filled($record->notes))
                ->components([
                    TextEntry::make('notes')
                        ->label(__('admin.fields.notes'))
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
