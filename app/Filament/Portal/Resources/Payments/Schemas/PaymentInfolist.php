<?php

namespace App\Filament\Portal\Resources\Payments\Schemas;

use App\Models\PaymentMethod;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class PaymentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.payment'))
                ->columns(3)
                ->components([
                    TextEntry::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->copyable()
                        ->fontFamily('mono'),
                    TextEntry::make('payment_date')
                        ->label(__('admin.fields.payment_date'))
                        ->date('d/m/Y'),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.statuses.payment.{$state}"))
                        ->color(fn (string $state): string => match ($state) {
                            'captured', 'reconciled', 'settled' => 'success',
                            'initiated', 'authorized' => 'warning',
                            'failed', 'bounced', 'refunded', 'voided' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('amount')
                        ->label(__('admin.fields.amount'))
                        ->money('EGP')->weight('bold'),
                    TextEntry::make('method')
                        ->label(__('admin.fields.method'))
                        ->badge()
                        ->formatStateUsing(fn (?string $state) => PaymentMethod::labelFor($state)),
                    TextEntry::make('gateway')->label(__('admin.fields.gateway'))->placeholder('—'),
                    TextEntry::make('gateway_transaction_id')->label(__('admin.fields.gateway_transaction_id'))->placeholder('—'),
                    TextEntry::make('cheque_number')->label(__('admin.fields.cheque_number'))->placeholder('—'),
                    TextEntry::make('cheque_clearance_date')->label(__('admin.fields.cheque_clearance_date'))->date('d/m/Y')->placeholder('—'),
                ]),
            // What this receipt settled — the durable "what did my money pay for" record. Mirrors
            // the email receipt + the mobile API, which already list the per-invoice split.
            Section::make(__('admin.sections.allocations'))
                ->visible(fn ($record) => $record->invoices()->exists())
                ->components([
                    RepeatableEntry::make('invoices')
                        ->hiddenLabel()
                        ->columns(2)
                        ->schema([
                            TextEntry::make('number')
                                ->label(__('admin.resources.invoice.singular')),
                            TextEntry::make('pivot.allocated_amount')
                                ->label(__('admin.fields.allocated_amount'))
                                ->money('EGP'),
                        ]),
                ]),
            Section::make(__('admin.sections.notes'))
                ->visible(fn ($record) => filled($record->notes))
                ->components([
                    TextEntry::make('notes')->label(__('admin.fields.notes'))->columnSpanFull(),
                ]),
        ]);
    }
}
