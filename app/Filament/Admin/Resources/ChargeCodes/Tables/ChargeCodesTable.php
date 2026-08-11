<?php

namespace App\Filament\Admin\Resources\ChargeCodes\Tables;

use App\Enums\InvoiceItemType;
use App\Filament\Admin\Resources\ChargeCodes\ChargeCodeResource;
use App\Models\ChargeCode;
use App\Models\TaxCode;
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

                // The tax this code is billed under, with the rate it currently resolves to
                // underneath. The rate matters as much as the name — "VAT — standard rate" alone
                // leaves the operator to remember what standard is today, and a code on a schedule
                // rate of its own would look identical to one on 14%.
                TextColumn::make('tax_code')
                    ->label(__('admin.fields.tax_code'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => TaxCode::labelFor($state)
                        ?? __('admin.charge_codes.tax_unclassified'))
                    ->color(fn (?string $state) => $state === null ? 'warning' : 'gray')
                    // Resolved through Vat, not read off the tax code — so an unclassified charge
                    // code shows the rate the FLOOR would actually bill it at, rather than a blank
                    // that reads as "no tax".
                    ->description(fn (ChargeCode $record) => number_format(Vat::rateForType($record->code), 2).'%'),

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
