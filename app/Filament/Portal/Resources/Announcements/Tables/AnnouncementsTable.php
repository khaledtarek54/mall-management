<?php

namespace App\Filament\Portal\Resources\Announcements\Tables;

use App\Models\Announcement;
use App\Support\Portal;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

/**
 * The retailer's own notice board. Unread rows are visually distinct, pinned first — the same
 * `feedOrder()` the mobile app renders, so a tenant looking at both sees the same thing at the top.
 */
class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.announcements.fields.title'))
                    // Unread reads bold; read reads normal. The one affordance a notice board needs.
                    ->weight(fn ($record) => $record->recipients->first()?->read_at === null ? 'bold' : null)
                    ->searchable()
                    ->description(fn ($record) => str($record->body)->limit(90)),

                TextColumn::make('category')
                    ->label(__('admin.announcements.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __("admin.announcements.categories.{$state}"))
                    ->color(fn ($state) => $state === Announcement::CATEGORY_EMERGENCY ? 'danger' : 'gray'),

                TextColumn::make('sent_at')
                    ->label(__('admin.announcements.fields.sent_at'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),

                TextColumn::make('expires_at')
                    ->label(__('admin.announcements.fields.expires_at'))
                    ->dateTime('d/m/Y')
                    ->placeholder(__('admin.announcements.no_expiry'))
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('category')
                    ->label(__('admin.announcements.fields.category'))
                    ->options(fn () => collect(Announcement::CATEGORIES)
                        ->mapWithKeys(fn (string $c) => [$c => __("admin.announcements.categories.{$c}")])),

                TernaryFilter::make('unread')
                    ->label(__('admin.announcements.fields.read'))
                    ->placeholder(__('admin.announcements.filters.read_all'))
                    ->trueLabel(__('admin.announcements.filters.unread_only'))
                    ->falseLabel(__('admin.announcements.filters.read_only'))
                    // The tenant scope is already applied by the resource's getEloquentQuery, but
                    // this clause re-states it: an unqualified `whereHas('recipients', unread)`
                    // would match a notice ANOTHER tenant has not read.
                    ->queries(
                        true: fn ($query) => $query->whereHas(
                            'recipients',
                            fn ($q) => $q->where('tenant_id', Portal::tenantId())->whereNull('read_at')
                        ),
                        false: fn ($query) => $query->whereHas(
                            'recipients',
                            fn ($q) => $q->where('tenant_id', Portal::tenantId())->whereNotNull('read_at')
                        ),
                        blank: fn ($query) => $query,
                    ),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->emptyStateIcon('heroicon-o-megaphone')
            ->emptyStateHeading(__('admin.empty.portal_announcements.heading'))
            ->emptyStateDescription(__('admin.empty.portal_announcements.description'))
            // Pinned first, then newest — `feedOrder()` in table form. Applied as an explicit sort
            // rather than in the query so the column headers still work.
            ->defaultSort(fn ($query) => $query->feedOrder());
    }
}
