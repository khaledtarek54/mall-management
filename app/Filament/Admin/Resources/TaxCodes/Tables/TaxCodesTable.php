<?php

namespace App\Filament\Admin\Resources\TaxCodes\Tables;

use App\Filament\Admin\Resources\TaxCodes\TaxCodeResource;
use App\Models\TaxCode;
use App\Support\PostingRoles;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class TaxCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.tax_code'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable()
                    ->sortable(),

                TextColumn::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->searchable(),

                TextColumn::make('name_ar')
                    ->label(__('admin.fields.name_ar'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('family')
                    ->label(__('admin.fields.tax_family'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => __('admin.enums.tax_family.'.$state))
                    ->color(fn (?string $state) => match ($state) {
                        TaxCode::FAMILY_VAT => 'success',
                        TaxCode::FAMILY_WITHHOLDING => 'warning',
                        default => 'info',
                    }),

                TextColumn::make('direction')
                    ->label(__('admin.fields.tax_direction'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => __('admin.enums.tax_direction.'.$state))
                    ->color('gray'),

                TextColumn::make('treatment')
                    ->label(__('admin.fields.tax_treatment'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => __('admin.enums.tax_treatment.'.$state))
                    ->color(fn (?string $state) => $state === TaxCode::STANDARD ? 'gray' : 'warning')
                    ->toggleable(),

                // The whole point of the screen: what this tax charges today, and since when. A
                // code with no ladder yet says so rather than reading as 0% — an accountant who has
                // added the code but not the rate needs to see the difference.
                TextColumn::make('current_rate')
                    ->label(__('admin.fields.current_rate'))
                    ->state(fn (TaxCode $record) => $record->currentRate())
                    ->formatStateUsing(fn (?float $state) => $state === null
                        ? __('admin.tax_codes.no_rate')
                        : number_format($state, 2).'%')
                    ->badge()
                    ->color(fn (?float $state) => $state === null ? 'danger' : 'success')
                    ->description(fn (TaxCode $record) => ($rung = $record->rates()->orderByDesc('effective_from')->first())
                        ? __('admin.tax_codes.in_force_from', ['date' => $rung->effective_from->format('d/m/Y')])
                        : $record->statutory_reference),

                TextColumn::make('posting_role')
                    ->label(__('admin.fields.posting_role'))
                    ->badge()
                    ->placeholder(__('admin.tax_codes.no_role'))
                    ->formatStateUsing(fn (?string $state) => $state ? PostingRoles::label($state) : null)
                    ->toggleable(),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('family')
                    ->label(__('admin.fields.tax_family'))
                    ->options(fn () => __('admin.enums.tax_family')),
                SelectFilter::make('direction')
                    ->label(__('admin.fields.tax_direction'))
                    ->options(fn () => __('admin.enums.tax_direction')),
                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (TaxCode $record) => TaxCodeResource::canView($record)),
                EditAction::make(),
            ])
            ->emptyStateIcon('heroicon-o-receipt-percent')
            ->emptyStateHeading(__('admin.empty.tax_codes.heading'))
            ->emptyStateDescription(__('admin.empty.tax_codes.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.tax_codes.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
