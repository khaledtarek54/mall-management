<?php

namespace App\Filament\Admin\Resources\Announcements\Tables;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
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
            ->filters([
                // sent_at is null until the broadcast lands, so this separates
                // "queued/failed to send" from "actually delivered".
                TernaryFilter::make('sent')
                    ->label(__('admin.announcements.filters.sent'))
                    ->placeholder(__('admin.announcements.filters.sent_all'))
                    ->trueLabel(__('admin.announcements.filters.sent_only'))
                    ->falseLabel(__('admin.announcements.filters.pending_only'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('sent_at'),
                        false: fn ($query) => $query->whereNull('sent_at'),
                        blank: fn ($query) => $query,
                    ),
                SelectFilter::make('created_by')
                    ->label(__('admin.announcements.fields.created_by'))
                    ->relationship('creator', 'name')
                    ->searchable()
                    ->preload(),
                Filter::make('sent_at')
                    ->label(__('admin.announcements.fields.sent_at'))
                    ->schema([
                        DatePicker::make('from')->label(__('admin.filters.date_from'))->native(false),
                        DatePicker::make('until')->label(__('admin.filters.date_until'))->native(false),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['from'] ?? null, fn ($q, $d) => $q->whereDate('sent_at', '>=', $d))
                        ->when($data['until'] ?? null, fn ($q, $d) => $q->whereDate('sent_at', '<=', $d))),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => AnnouncementResource::canView($record))
                    ->authorize(fn ($record) => AnnouncementResource::canView($record)),
                // No edit — an announcement is immutable once broadcast. Only a
                // super_admin can delete one (canDelete = isSuperAdmin).
                DeleteAction::make()->visible(fn ($record) => AnnouncementResource::canDelete($record)),
            ])
            ->emptyStateIcon('heroicon-o-megaphone')
            ->emptyStateHeading(__('admin.empty.announcements.heading'))
            ->emptyStateDescription(__('admin.empty.announcements.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.announcements.cta'))
                    ->icon('heroicon-o-plus')
                    ->visible(fn () => AnnouncementResource::canCreate()),
            ])
            ->defaultSort('created_at', 'desc');
    }
}
