<?php

namespace App\Filament\Admin\Resources\Announcements\Tables;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Models\Announcement;
use App\Models\User;
use App\Services\SendAnnouncementAction;
use App\Support\Filament\EntitySelectFilter;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;

class AnnouncementsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            // withCount on the read receipts: the read RATE is the column an operator actually
            // reads, and deriving it per row would be an N+1 on the one screen that always has
            // rows. Deliberately not stored on `announcements` — a second truth about the same
            // fact drifts the first time a receipt is stamped outside the one code path.
            ->modifyQueryUsing(fn ($query) => $query->withCount('reads'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.announcements.fields.title'))
                    ->weight('bold')
                    ->searchable()
                    ->description(fn ($record) => str($record->body)->limit(80)),

                TextColumn::make('category')
                    ->label(__('admin.announcements.fields.category'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __("admin.announcements.categories.{$state}"))
                    ->color(fn ($state) => $state === Announcement::CATEGORY_EMERGENCY ? 'danger' : 'gray'),

                IconColumn::make('is_pinned')
                    ->label(__('admin.announcements.fields.is_pinned'))
                    ->boolean()
                    ->trueIcon('heroicon-s-bookmark')
                    ->falseIcon('heroicon-o-minus-small')
                    ->trueColor('warning')
                    ->falseColor('gray')
                    ->toggleable(),

                TextColumn::make('asset.name')
                    ->label(__('admin.announcements.fields.property'))
                    ->badge()
                    ->color('gray'),

                TextColumn::make('status')
                    ->label(__('admin.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn ($state) => __("admin.announcements.statuses.{$state}"))
                    ->color(fn ($state) => match ($state) {
                        Announcement::STATUS_SENT => 'success',
                        Announcement::STATUS_SCHEDULED => 'info',
                        default => 'gray',
                    }),

                // "12 / 40" — how many of the stores that were sent it have opened it. The single
                // most useful number on this screen, and the one nothing in the system could
                // answer before notices kept a recipient list.
                TextColumn::make('reads_count')
                    ->label(__('admin.announcements.fields.read_rate'))
                    ->alignEnd()
                    ->state(fn ($record) => $record->recipients_count > 0
                        ? "{$record->reads_count} / {$record->recipients_count}"
                        : '—')
                    ->color(fn ($record) => $record->recipients_count > 0 && $record->reads_count === 0
                        ? 'warning'
                        : null),

                TextColumn::make('sent_at')
                    ->label(__('admin.announcements.fields.sent_at'))
                    ->dateTime('d/m/Y H:i')
                    // A scheduled notice has no sent_at yet, and its publish time is the date the
                    // operator is actually looking for.
                    ->description(fn ($record) => $record->publish_at !== null && ! $record->isSent()
                        ? __('admin.announcements.due_at', ['at' => $record->publish_at->format('d/m/Y H:i')])
                        : null)
                    ->placeholder('—')
                    ->sortable(),

                TextColumn::make('creator.name')
                    ->label(__('admin.announcements.fields.created_by'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.fields.status'))
                    ->options(fn () => collect(Announcement::STATUSES)
                        ->mapWithKeys(fn (string $s) => [$s => __("admin.announcements.statuses.{$s}")])),

                SelectFilter::make('category')
                    ->label(__('admin.announcements.fields.category'))
                    ->options(fn () => collect(Announcement::CATEGORIES)
                        ->mapWithKeys(fn (string $c) => [$c => __("admin.announcements.categories.{$c}")])),

                EntitySelectFilter::make('created_by')
                    ->label(__('admin.announcements.fields.created_by'))
                    ->relationship('creator')
                    ->entity(User::class),

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
                // The notice + its read receipts. Read without opening an edit form — less
                // friction, and no write surface for view-only roles.
                ViewAction::make()
                    ->visible(fn ($record) => AnnouncementResource::canView($record))
                    ->authorize(fn ($record) => AnnouncementResource::canView($record)),

                EditAction::make()
                    ->visible(fn ($record) => AnnouncementResource::canEdit($record))
                    ->authorize(fn ($record) => AnnouncementResource::canEdit($record)),

                // Broadcast a draft, or a scheduled notice ahead of its time.
                //
                // Gated TWICE on one named predicate: `visible()` shapes the UI, `abort_unless`
                // inside `action()` is the gate. `visible()` is not an authorization check — it is
                // a statement of intent that happens to also disable the action on the version we
                // ship, and an upstream release could quietly change that for every such action at
                // once.
                Action::make('send')
                    ->label(__('admin.announcements.actions.send'))
                    ->icon('heroicon-o-paper-airplane')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalHeading(__('admin.announcements.actions.send'))
                    // Naming the audience in the confirmation, not just "are you sure": this is a
                    // one-way push to every retailer in the mall, and there is no unsend.
                    ->modalDescription(fn ($record) => __('admin.announcements.actions.send_confirm', [
                        'property' => $record->asset?->name ?? '—',
                    ]))
                    ->visible(fn ($record) => ! $record->isSent() && AnnouncementResource::canSend())
                    ->authorize(fn ($record) => AnnouncementResource::canSend())
                    ->action(function ($record) {
                        abort_unless(AnnouncementResource::canSend(), 403);
                        // Re-checked against the record, not the button: a second operator may
                        // have sent it while this page was open. The service's own sent_at guard
                        // makes the race harmless; this is what makes the operator's feedback
                        // honest rather than reporting a send that was a no-op.
                        abort_if($record->isSent(), 403);

                        $reached = app(SendAnnouncementAction::class)->handle($record);

                        Notification::make()
                            ->title(__('admin.announcements.sent_toast', ['count' => $reached]))
                            ->success()
                            ->send();
                    }),

                // Only a super_admin can delete one (canDelete = isSuperAdmin).
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
