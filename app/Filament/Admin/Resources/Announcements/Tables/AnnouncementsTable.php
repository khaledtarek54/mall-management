<?php

namespace App\Filament\Admin\Resources\Announcements\Tables;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use Filament\Actions\DeleteAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.announcements.fields.title'))
                    ->weight('bold')
                    ->searchable()
                    ->description(fn ($record) => str($record->body)->limit(80)),
                TextColumn::make('asset.name')
                    ->label(__('admin.announcements.fields.property'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('recipients_count')
                    ->label(__('admin.announcements.fields.recipients'))
                    ->numeric()
                    ->alignEnd(),
                TextColumn::make('sent_at')
                    ->label(__('admin.announcements.fields.sent_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.announcements.pending'))
                    ->sortable(),
                TextColumn::make('creator.name')
                    ->label(__('admin.announcements.fields.created_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->recordActions([
                // No edit — an announcement is immutable once broadcast. Only a
                // super_admin can delete one (canDelete = isSuperAdmin).
                DeleteAction::make()->visible(fn ($record) => AnnouncementResource::canDelete($record)),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
