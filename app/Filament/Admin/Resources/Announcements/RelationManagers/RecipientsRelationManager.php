<?php

namespace App\Filament\Admin\Resources\Announcements\RelationManagers;

use App\Services\SendAnnouncementAction;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who was sent this notice, and who has opened it.
 *
 * **This is the answer to the question the whole recipient table exists for.** Before it,
 * `recipients_count` was a number: the operator knew forty stores were messaged and had no way of
 * knowing whether any of them read it — so "we told you" was an assertion, not a record, and the
 * one conversation it needed to survive (a retailer saying nobody told them) it could not.
 *
 * Read-only by construction: every row here is written by
 * {@see SendAnnouncementAction} at broadcast time and stamped by the tenant's own
 * read, on their phone or in the portal. There is nothing for an operator to edit, and a marked
 * receipt an operator could set by hand would be worth nothing.
 *
 * A null `notified_at` is the honest half: the blast isolates per-recipient failures rather than
 * stranding the record, and this is where the ones it missed are visible instead of being quietly
 * absent from a count.
 */
class RecipientsRelationManager extends RelationManager
{
    protected static string $relationship = 'recipients';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.announcements.recipients_title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return auth()->user()?->can('announcements.view') ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: AnnouncementRecipient carries no `search_text` blob and no column
            // here is searchable, so the box would always return nothing — which reads as "no
            // such tenant" rather than "this table cannot answer that". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with(['tenant', 'readBy']))
            ->columns([
                TextColumn::make('tenant.name')
                    ->label(__('admin.announcements.fields.recipient'))
                    ->weight('bold')
                    ->sortable(),

                IconColumn::make('read_at')
                    ->label(__('admin.announcements.fields.read'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('gray')
                    ->getStateUsing(fn ($record) => $record->read_at !== null),

                TextColumn::make('read_at')
                    ->label(__('admin.announcements.fields.read_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.announcements.not_read'))
                    ->sortable(),

                TextColumn::make('readBy.name')
                    ->label(__('admin.announcements.fields.read_by'))
                    // Null is normal, not missing data: the mobile API authenticates the tenant
                    // COMPANY and has no user to attribute the read to. Said in the placeholder
                    // so nobody reads a dash as a bug.
                    ->placeholder(__('admin.announcements.read_on_mobile'))
                    ->toggleable(),

                TextColumn::make('notified_at')
                    ->label(__('admin.announcements.fields.notified_at'))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder(__('admin.announcements.delivery_failed'))
                    ->color(fn ($record) => $record->notified_at === null ? 'danger' : null)
                    ->toggleable(),
            ])
            ->filters([
                TernaryFilter::make('read')
                    ->label(__('admin.announcements.fields.read'))
                    ->placeholder(__('admin.announcements.filters.read_all'))
                    ->trueLabel(__('admin.announcements.filters.read_only'))
                    ->falseLabel(__('admin.announcements.filters.unread_only'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('read_at'),
                        false: fn ($query) => $query->whereNull('read_at'),
                        blank: fn ($query) => $query,
                    ),
            ])
            // Unread first: the operator opening this screen is chasing the ones who have not seen
            // it, not admiring the ones who have.
            ->defaultSort('read_at', 'asc');
    }
}
