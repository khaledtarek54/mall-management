<?php

namespace App\Filament\Admin\Resources\Vendors\Tables;

use App\Filament\Admin\Resources\Vendors\VendorResource;
use App\Models\Vendor;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class VendorsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.vendor.name'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('type')
                    ->label(__('admin.tables.vendor.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.vendor_type.{$state}"))
                    ->color('gray'),
                TextColumn::make('phone')
                    ->label(__('admin.tables.vendor.phone'))
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('email')
                    ->label(__('admin.fields.email'))
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('active_contracts_count')
                    ->label(__('admin.tables.vendor.contracts'))
                    ->badge()
                    ->color('info'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.vendor.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'blacklisted' => 'danger',
                        default => 'gray',
                    }),
                TextColumn::make('coi_status')
                    ->label(__('admin.vendors.compliance.coi_status'))
                    ->badge()
                    ->state(fn (Vendor $record) => match (true) {
                        $record->coi_expires_at === null => __('admin.vendors.compliance.none'),
                        $record->coiDaysToExpiry() < 0 => __('admin.vendors.compliance.expired'),
                        $record->coiDaysToExpiry() <= 30 => __('admin.vendors.compliance.expiring'),
                        default => $record->coi_expires_at->format('Y-m-d'),
                    })
                    ->color(fn (Vendor $record) => match (true) {
                        $record->coi_expires_at === null => 'gray',
                        $record->coiDaysToExpiry() < 0 => 'danger',
                        $record->coiDaysToExpiry() <= 30 => 'warning',
                        default => 'success',
                    }),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.tables.vendor.type'))
                    ->options(fn () => __('admin.enums.vendor_type')),
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.vendor')),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()->visible(fn ($record) => VendorResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => VendorResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('name')
            ->emptyStateIcon('heroicon-o-building-office-2')
            ->emptyStateHeading(__('admin.empty.vendors.heading'))
            ->emptyStateDescription(__('admin.empty.vendors.description'))
            ->emptyStateActions([
                \Filament\Actions\CreateAction::make()
                    ->label(__('admin.empty.vendors.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
