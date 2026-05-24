<?php

namespace App\Filament\Admin\Resources\CreditNotes\Tables;

use App\Filament\Admin\Resources\CreditNotes\CreditNoteResource;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class CreditNotesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($q) => $q->with(['tenant', 'invoice']))
            ->columns([
                TextColumn::make('number')
                    ->label(__('admin.tables.credit_note.number'))
                    ->searchable()
                    ->copyable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('tenant.name')
                    ->label(__('admin.tables.credit_note.tenant'))
                    ->searchable()
                    ->weight('bold'),
                TextColumn::make('invoice.number')
                    ->label(__('admin.fields.invoice'))
                    ->placeholder('—')
                    ->fontFamily('mono'),
                TextColumn::make('reason')
                    ->label(__('admin.fields.credit_note_reason'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.credit_note_reason.{$state}"))
                    ->color('gray'),
                TextColumn::make('issue_date')
                    ->label(__('admin.fields.issue_date'))
                    ->date('d/m/Y')
                    ->sortable(),
                TextColumn::make('total')
                    ->label(__('admin.tables.credit_note.total'))
                    ->money('EGP')
                    ->sortable()
                    ->alignRight(),
                TextColumn::make('applied_amount')
                    ->label(__('admin.tables.credit_note.applied'))
                    ->money('EGP')
                    ->color('info')
                    ->alignRight(),
                TextColumn::make('balance')
                    ->label(__('admin.tables.credit_note.balance'))
                    ->money('EGP')
                    ->color(fn ($state) => $state > 0 ? 'success' : 'gray')
                    ->weight('bold')
                    ->alignRight(),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.statuses.credit_note.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'issued' => 'info',
                        'applied' => 'success',
                        'void' => 'gray',
                        default => 'warning',
                    }),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.filters.status'))
                    ->options(fn () => __('admin.statuses.credit_note')),
                SelectFilter::make('reason')
                    ->label(__('admin.fields.credit_note_reason'))
                    ->options(fn () => __('admin.enums.credit_note_reason')),
                SelectFilter::make('tenant_id')
                    ->label(__('admin.filters.tenant'))
                    ->relationship('tenant', 'name')
                    ->searchable()
                    ->preload(),
                TrashedFilter::make(),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn ($record) => CreditNoteResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->visible(fn () => CreditNoteResource::canDeleteAny()),
                ]),
            ])
            ->defaultSort('issue_date', 'desc');
    }
}
