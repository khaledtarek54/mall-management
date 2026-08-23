<?php

namespace App\Filament\Admin\Resources\CustomFields\Tables;

use App\Models\CustomField;
use App\Support\CustomFields;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class CustomFieldsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // The operator's own order, grouped by the record type it applies to — which is how
            // the question is asked ("what do we record about a tenant?"), never alphabetically.
            ->defaultSort('model')
            ->columns([
                TextColumn::make('model')
                    ->label(__('admin.custom_fields.model'))
                    ->formatStateUsing(fn (string $state): string => __("admin.custom_fields.models.{$state}"))
                    ->badge()
                    ->sortable(),

                TextColumn::make('label_en')
                    ->label(__('admin.custom_fields.label_en'))
                    ->searchable()
                    ->description(fn (CustomField $record): string => $record->label_ar),

                TextColumn::make('key')
                    ->label(__('admin.custom_fields.key'))
                    ->fontFamily('mono')
                    ->size('sm')
                    ->searchable()
                    ->toggleable(),

                TextColumn::make('type')
                    ->label(__('admin.custom_fields.type'))
                    ->formatStateUsing(fn (string $state): string => __("admin.custom_fields.types.{$state}"))
                    ->badge()
                    ->color('gray'),

                IconColumn::make('is_required')
                    ->label(__('admin.custom_fields.is_required'))
                    ->boolean()
                    ->toggleable(),

                TextColumn::make('sort_order')
                    ->label(__('admin.fields.sort_order'))
                    ->alignEnd()
                    ->sortable()
                    ->toggleable(isToggledHiddenByDefault: true),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('model')
                    ->label(__('admin.custom_fields.model'))
                    ->options(fn (): array => collect(array_keys(CustomFields::EXTENSIBLE))
                        ->mapWithKeys(fn (string $alias): array => [$alias => __("admin.custom_fields.models.{$alias}")])
                        ->all()),

                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
            ])
            ->recordActions([
                // A read-only view, for the role that holds `.view` and not `.edit` — the same
                // pairing every other catalogue table offers. Its schema is the resource's own form
                // rendered disabled, so it cannot drift from the fields that exist.
                ViewAction::make(),
                EditAction::make(),
            ])
            ->emptyStateIcon('heroicon-o-adjustments-horizontal')
            ->emptyStateHeading(__('admin.custom_fields.plural'))
            ->emptyStateDescription(__('admin.custom_fields.section_help'));
    }
}
