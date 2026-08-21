<?php

namespace App\Filament\Admin\Resources\VendorDocumentTypes\Tables;

use App\Models\VendorDocumentType;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class VendorDocumentTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->columns([
                TextColumn::make('code')->label(__('admin.fields.code'))->searchable()->sortable(),

                TextColumn::make('label')
                    ->label(__('admin.fields.name'))
                    ->state(fn (VendorDocumentType $record): string => $record->label()),

                IconColumn::make('blocks_dispatch')
                    ->label(__('admin.vendor_document_types_screen.blocks_dispatch'))
                    ->boolean()
                    // The consequence, on the list, because this is the only column here that
                    // changes who may be sent to site.
                    ->tooltip(fn (VendorDocumentType $record) => $record->blocks_dispatch
                        ? __('admin.vendor_document_types_screen.blocks_dispatch_yes')
                        : __('admin.vendor_document_types_screen.blocks_dispatch_no')),

                TextColumn::make('documents_count')
                    ->label(__('admin.vendor_document_types_screen.on_file'))
                    // What makes a type undeletable, shown so the refusal is not a surprise.
                    ->counts('documents')
                    ->badge(),

                IconColumn::make('is_active')->label(__('admin.fields.is_active'))->boolean(),
            ])
            ->filters([
                TernaryFilter::make('is_active')->label(__('admin.fields.is_active')),
                TernaryFilter::make('blocks_dispatch')->label(__('admin.vendor_document_types_screen.blocks_dispatch')),
            ])
            ->recordActions([
                // A read-only view, for the role that holds `.view` and not `.edit`. Its schema is the
                // resource's own form rendered disabled, so it cannot drift from the fields that exist.
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
