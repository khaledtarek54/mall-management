<?php

namespace App\Filament\Admin\Resources\FixedAssets\Tables;

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class FixedAssetsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.fixed_assets.fields.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('tag')
                    ->label(__('admin.fixed_assets.fields.tag'))
                    ->fontFamily('mono')
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.fixed_assets.fields.property'))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),
                TextColumn::make('category')
                    ->label(__('admin.fixed_assets.fields.category'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('acquisition_date')
                    ->label(__('admin.fixed_assets.fields.acquisition_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('acquisition_cost')
                    ->label(__('admin.fixed_assets.fields.acquisition_cost'))
                    ->money('EGP')
                    ->sortable(),
                TextColumn::make('monthly')
                    ->label(__('admin.fixed_assets.fields.monthly'))
                    // Pure calc (cost − salvage) ÷ life — no query.
                    ->state(fn (FixedAsset $record) => app(DepreciationService::class)->monthlyAmount($record))
                    ->money('EGP')
                    ->toggleable(),
                TextColumn::make('accumulated')
                    ->label(__('admin.fixed_assets.fields.accumulated'))
                    // Derived SUM(entries) from the resource's withSum subquery.
                    ->money('EGP')
                    ->default(0)
                    ->color('warning'),
                TextColumn::make('net_book_value')
                    ->label(__('admin.fixed_assets.fields.net_book_value'))
                    ->state(fn (FixedAsset $record) => round((float) $record->acquisition_cost - (float) ($record->accumulated ?? 0), 2))
                    ->money('EGP')
                    ->weight('bold')
                    ->color('success'),
                TextColumn::make('status')
                    ->label(__('admin.fixed_assets.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.fixed_assets.statuses.$state"))
                    ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
            ])
            ->recordActions([
                Action::make('dispose')
                    ->label(__('admin.fixed_assets.actions.dispose'))
                    ->icon('heroicon-o-archive-box-x-mark')
                    ->color('danger')
                    ->requiresConfirmation()
                    // Only active assets, and only if the user may edit.
                    ->visible(fn (FixedAsset $record) => $record->status === 'active' && FixedAssetResource::canEdit($record))
                    ->authorize(fn (FixedAsset $record) => FixedAssetResource::canEdit($record))
                    ->schema([
                        DatePicker::make('disposed_on')
                            ->label(__('admin.fixed_assets.fields.acquisition_date'))
                            ->default(now())
                            ->required()
                            ->native(false),
                    ])
                    ->action(function (array $data, FixedAsset $record): void {
                        // Server-side re-check (authorize can't see form tampering of a terminal record).
                        abort_unless(FixedAssetResource::canEdit($record) && $record->status === 'active', 403);
                        $record->update([
                            'status' => 'disposed',
                            'disposed_on' => $data['disposed_on'],
                        ]);
                        Notification::make()->title(__('admin.fixed_assets.disposed'))->success()->send();
                    }),
                EditAction::make()->visible(fn (FixedAsset $record) => FixedAssetResource::canEdit($record)),
            ])
            ->defaultSort('name');
    }
}
