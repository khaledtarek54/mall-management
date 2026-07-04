<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\Custody;
use App\Models\CustodyTransaction;
use App\Services\SettleCustodyService;
use App\Support\Modules;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Custody settlements (module 25) — record an `expense` (with a category → P&L account)
 * or a `return` of unspent cash, each reducing the custody's outstanding. Gated on
 * `custodies.settle`; the SettleCustodyService re-checks outstanding under a lock so a
 * settlement can never exceed what's in custody.
 */
class CustodyTransactionsRelationManager extends RelationManager
{
    protected static string $relationship = 'transactions';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.custodies.settlements');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return Modules::enabled('custodies') && (auth()->user()?->can('custodies.view') ?? false);
    }

    private function canSettle(): bool
    {
        return (auth()->user()?->can('custodies.settle') ?? false)
            && $this->getOwnerRecord()->outstanding() > 0;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('createdBy'))
            ->columns([
                TextColumn::make('transaction_date')
                    ->label(__('admin.custodies.txn_fields.date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('type')
                    ->label(__('admin.custodies.txn_fields.type'))
                    ->formatStateUsing(fn (string $state) => __("admin.custodies.types.$state"))
                    ->badge()
                    ->color(fn (string $state) => $state === 'return' ? 'info' : 'warning'),
                TextColumn::make('category')
                    ->label(__('admin.custodies.txn_fields.category'))
                    ->formatStateUsing(fn (?string $state) => $state ? (__('admin.enums.vendor_bill_category')[$state] ?? $state) : '—')
                    ->placeholder('—'),
                TextColumn::make('amount')
                    ->label(__('admin.custodies.txn_fields.amount'))
                    ->money('EGP'),
                TextColumn::make('createdBy.name')
                    ->label(__('admin.custodies.txn_fields.by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->headerActions([
                Action::make('record_expense')
                    ->label(__('admin.custodies.actions.record_expense'))
                    ->icon('heroicon-o-receipt-percent')
                    ->color('warning')
                    ->visible(fn () => $this->canSettle())
                    ->authorize(fn () => auth()->user()?->can('custodies.settle') ?? false)
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('admin.custodies.txn_fields.amount'))
                            ->numeric()->minValue(0.01)
                            ->maxValue(fn () => $this->getOwnerRecord()->outstanding())
                            ->required()->prefix('EGP'),
                        Select::make('category')
                            ->label(__('admin.custodies.txn_fields.category'))
                            ->options(fn () => __('admin.enums.vendor_bill_category'))
                            ->default('other')->required()->native(false),
                        DatePicker::make('transaction_date')
                            ->label(__('admin.custodies.txn_fields.date'))
                            ->default(now())->required()->native(false),
                        Textarea::make('notes')->label(__('admin.custodies.fields.purpose'))->rows(2)->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(auth()->user()?->can('custodies.settle') ?? false, 403);
                        /** @var Custody $custody */
                        $custody = $this->getOwnerRecord();
                        app(SettleCustodyService::class)->settle($custody, array_merge($data, ['type' => 'expense']));
                        Notification::make()->title(__('admin.custodies.expensed'))->success()->send();
                    }),
                Action::make('record_return')
                    ->label(__('admin.custodies.actions.record_return'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('info')
                    ->visible(fn () => $this->canSettle())
                    ->authorize(fn () => auth()->user()?->can('custodies.settle') ?? false)
                    ->schema([
                        TextInput::make('amount')
                            ->label(__('admin.custodies.txn_fields.amount'))
                            ->numeric()->minValue(0.01)
                            ->maxValue(fn () => $this->getOwnerRecord()->outstanding())
                            ->default(fn () => $this->getOwnerRecord()->outstanding())
                            ->required()->prefix('EGP'),
                        Select::make('method')
                            ->label(__('admin.custodies.txn_fields.method'))
                            ->options(['cash' => __('admin.employees.methods.cash'), 'bank' => __('admin.employees.methods.bank')])
                            ->default('cash')->required()->native(false),
                        DatePicker::make('transaction_date')
                            ->label(__('admin.custodies.txn_fields.date'))
                            ->default(now())->required()->native(false),
                        Textarea::make('notes')->label(__('admin.custodies.fields.purpose'))->rows(2)->columnSpanFull(),
                    ])
                    ->action(function (array $data): void {
                        abort_unless(auth()->user()?->can('custodies.settle') ?? false, 403);
                        /** @var Custody $custody */
                        $custody = $this->getOwnerRecord();
                        app(SettleCustodyService::class)->settle($custody, array_merge($data, ['type' => 'return']));
                        Notification::make()->title(__('admin.custodies.returned'))->success()->send();
                    }),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
