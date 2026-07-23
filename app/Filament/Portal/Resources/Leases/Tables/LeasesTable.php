<?php

namespace App\Filament\Portal\Resources\Leases\Tables;

use App\Models\Lease;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class LeasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.fields.reference'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('unit.code')
                    ->label(__('admin.resources.unit.singular'))
                    ->badge(),
                TextColumn::make('unit.asset.name')
                    ->label(__('admin.fields.property'))
                    ->toggleable(),
                TextColumn::make('base_rent_monthly')
                    ->label(__('admin.fields.base_rent_monthly'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('expiry_date')
                    ->label(__('admin.fields.expiry_date'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.lease.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'active' => 'success',
                        'terminated', 'expired', 'cancelled' => 'danger',
                        default => 'warning',
                    }),
            ])
            ->recordActions([
                ViewAction::make(),
                // The tenant's own signed lease document, if the operator has uploaded one. Private
                // disk — streamed via the media's own response, never a public URL.
                Action::make('downloadDocument')
                    ->label(__('admin.portal.lease.download_document'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->color('gray')
                    ->visible(fn (Lease $record) => $record->getMedia(Lease::DOCUMENTS_COLLECTION)->isNotEmpty())
                    ->action(function (Lease $record) {
                        $media = $record->getMedia(Lease::DOCUMENTS_COLLECTION)->last();
                        abort_if($media === null, 404);

                        return $media->toResponse(request());
                    }),
            ])
            ->defaultSort('id', 'desc')
            ->emptyStateIcon('heroicon-o-document-text')
            ->emptyStateHeading(__('admin.portal.lease.empty_heading'))
            ->emptyStateDescription(__('admin.portal.lease.empty_description'));
    }
}
