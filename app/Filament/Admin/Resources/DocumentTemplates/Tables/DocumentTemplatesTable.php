<?php

namespace App\Filament\Admin\Resources\DocumentTemplates\Tables;

use App\Filament\Admin\Resources\DocumentTemplates\DocumentTemplateResource;
use App\Models\DocumentTemplate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DocumentTemplatesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->searchable(false)
            ->defaultSort('key')
            ->columns([
                TextColumn::make('key')
                    ->label(__('admin.fields.document_block'))
                    ->formatStateUsing(fn (string $state): string => __('admin.document_templates_screen.blocks.'.str_replace('.', '_', $state)))
                    ->sortable(),

                TextColumn::make('asset.name')
                    ->label(__('admin.fields.property'))
                    // The house default is the ordinary row here, so it is named rather than blank.
                    ->placeholder(__('admin.document_templates_screen.house_default')),

                TextColumn::make('body_en')
                    ->label(__('admin.fields.body_en'))
                    ->limit(60)
                    ->wrap(),

                // Says which languages are actually written. A row with only one filled still
                // renders — the model falls back — but the operator should be able to see the gap.
                TextColumn::make('languages')
                    ->label(__('admin.document_templates_screen.languages'))
                    ->state(fn (DocumentTemplate $record): string => collect([
                        filled($record->body_en) ? 'EN' : null,
                        filled($record->body_ar) ? 'AR' : null,
                    ])->filter()->implode(' · ') ?: '—'),

                IconColumn::make('is_active')
                    ->label(__('admin.fields.is_active'))
                    ->boolean(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn (DocumentTemplate $record) => DocumentTemplateResource::canEdit($record))
                    ->authorize(fn (DocumentTemplate $record) => DocumentTemplateResource::canEdit($record)),
                DeleteAction::make()
                    ->visible(fn () => DocumentTemplateResource::canDeleteAny())
                    ->authorize(fn () => DocumentTemplateResource::canDeleteAny()),
            ]);
    }
}
