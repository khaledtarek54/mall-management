<?php

namespace App\Filament\Admin\Resources\ChargeCodes\Tables;

use App\Enums\InvoiceItemType;
use App\Filament\Admin\Resources\ChargeCodes\ChargeCodeResource;
use App\Models\ChargeCode;
use App\Support\PostingRoles;
use App\Support\Vat;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ChargeCodesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')
                    ->label(__('admin.fields.charge_code'))
                    ->fontFamily('mono')
                    ->size('xs')
                    ->searchable()
                    ->sortable()
                    // Say which codes the engine has logic for, rather than making the operator
                    // remember. These are the ones that cannot be deactivated.
                    ->description(fn (ChargeCode $record) => in_array($record->code, InvoiceItemType::values(), true)
                        ? __('admin.charge_codes.system')
                        : null),

                TextColumn::make('name_en')
                    ->label(__('admin.fields.name_en'))
                    ->searchable(),

                TextColumn::make('name_ar')
                    ->label(__('admin.fields.name_ar'))
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('posting_role')
                    ->label(__('admin.fields.posting_role'))
                    ->badge()
                    ->placeholder(__('admin.charge_codes.unmapped'))
                    ->formatStateUsing(fn (?string $state) => $state ? PostingRoles::label($state) : null)
                    ->color(fn (?string $state) => $state === null ? 'warning' : 'gray')
                    // The statement class, so a revenue code pointed at an expense account is
                    // visible without opening the row.
                    ->description(fn (ChargeCode $record) => ($g = $record->roleGroup())
                        ? PostingRoles::groupLabel($g)
                        : __('admin.charge_codes.falls_back')),

                // The rate this code bills at, not just its treatment — "Standard" alone leaves the
                // operator to remember what standard is today, and a code on its own schedule rate
                // would look identical to one on 14%.
                TextColumn::make('vat_treatment')
                    ->label(__('admin.fields.vat_treatment'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => match ($state) {
                        ChargeCode::VAT_EXEMPT => __('admin.charge_codes.vat_exempt'),
                        ChargeCode::VAT_ZERO_RATED => __('admin.charge_codes.vat_zero_rated'),
                        default => __('admin.charge_codes.vat_standard'),
                    })
                    ->color(fn (?string $state) => $state === ChargeCode::VAT_STANDARD ? 'success' : 'gray')
                    ->description(fn (ChargeCode $record) => $record->vat_treatment === ChargeCode::VAT_STANDARD
                        ? number_format(Vat::rateForType($record->code), 2).'%'
                        : null),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean()
                    ->sortable(),

                TextColumn::make('sort_order')
                    ->label(__('admin.fields.sort_order'))
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.fields.is_active')),
            ])
            ->recordActions([
                ViewAction::make()
                    ->visible(fn (ChargeCode $record) => ChargeCodeResource::canView($record)),
                EditAction::make(),
            ]);
    }
}
