<?php

namespace App\Filament\Concerns;

use App\Support\NotificationTargets;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification as Toast;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Filament\Support\Enums\Alignment;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Collection;

/**
 * **The notification centre, shared by both panels.**
 *
 * The bell is a peek, not a record. It shows a page of recent entries in a dropdown two lines
 * high, and once an alert scrolls out of it there is no way back to what it said. That matters
 * here more than in most systems: the alerts are things like "contract notice deadline passed —
 * it auto-renews", which somebody needs to be able to find again a week later and read in full.
 *
 * So each panel gets a real page, and this trait is the half of it that is identical on both
 * sides — the table, the filters, the details modal, the read/unread machinery. What is NOT shared
 * lives in the two page classes: the guard, the navigation slot, and the fact that `/admin` is
 * tenanted while `/portal` is not.
 *
 * Every row is scoped to `$user->notifications()`. There is no permission to check and none to
 * invent: a notification is addressed to exactly one reader, and the query never widens beyond the
 * one signed in.
 */
trait RendersNotificationCentre
{
    /** The signed-in reader, resolved through the panel's own guard (portal is not the web guard). */
    protected function reader(): ?Authenticatable
    {
        return Filament::auth()->user();
    }

    /**
     * This reader's bell entries. `data->format = filament` mirrors what the bell itself queries,
     * so the page and the dropdown can never disagree about what exists.
     */
    protected function notificationsQuery(): Builder
    {
        $reader = $this->reader();

        if (! $reader) {
            abort(401);
        }

        /** @var Builder $query */
        $query = $reader->notifications()->getQuery()->where('data->format', 'filament');

        return $query;
    }

    /**
     * Not named `table()`: `InteractsWithTable` already declares one, and two traits offering the
     * same method is a fatal collision rather than an override. Each page calls this from its own
     * `table()`, which is the method Filament looks for.
     */
    protected function notificationCentreTable(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->notificationsQuery())
            ->columns([
                // The unread marker is the icon itself: unread entries keep the notification's own
                // colour, read ones go grey. One glance separates "needs me" from "already dealt
                // with", without a second column spending width to say so.
                TextColumn::make('icon')
                    ->label('')
                    ->state(fn (DatabaseNotification $record): string => '')
                    ->icon(fn (DatabaseNotification $record): string => $record->data['icon'] ?? 'heroicon-o-bell')
                    ->color(fn (DatabaseNotification $record): string => $record->read_at
                        ? 'gray'
                        : ($record->data['color'] ?? 'primary'))
                    ->alignment(Alignment::Center)
                    ->width('1%'),

                TextColumn::make('title')
                    ->label(__('admin.notifications.centre.alert'))
                    ->state(fn (DatabaseNotification $record): string => $record->data['title'] ?? '—')
                    // Unread reads as bold, exactly as an unopened mail does. Nothing to learn.
                    ->weight(fn (DatabaseNotification $record): string => $record->read_at ? 'normal' : 'bold')
                    ->description(fn (DatabaseNotification $record): ?string => $record->data['body'] ?? null)
                    ->wrap()
                    ->searchable(query: fn (Builder $query, string $search): Builder => $query
                        ->where('data', 'like', '%'.$search.'%')),

                TextColumn::make('subject')
                    ->label(__('admin.notifications.centre.subject'))
                    ->state(fn (DatabaseNotification $record): string => $this->subjectLabel($record->type))
                    ->badge()
                    ->color('gray')
                    ->toggleable(),

                TextColumn::make('created_at')
                    ->label(__('admin.notifications.centre.when'))
                    ->dateTime('d/m/Y H:i')
                    ->description(fn (DatabaseNotification $record): string => $record->created_at?->diffForHumans() ?? '')
                    ->sortable(),
            ])
            // Clicking the row opens the full alert. That is the answer to "I can see 90 characters
            // of it and I need the rest" — and it is available for every entry, including the ones
            // with no record of their own to open.
            ->recordAction('details')
            ->recordActions([
                $this->detailsAction(),
                $this->openAction(),
                $this->readToggleAction(),
            ])
            ->filters([
                TernaryFilter::make('read')
                    ->label(__('admin.notifications.centre.status'))
                    ->placeholder(__('admin.notifications.centre.status_all'))
                    ->trueLabel(__('admin.notifications.centre.status_read'))
                    ->falseLabel(__('admin.notifications.centre.status_unread'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('read_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('read_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),

                // Grouped by WHAT the alert is about, not by notification class. Four separate
                // classes concern a work order; a reader hunting for "that SLA thing" wants the
                // work-order group, not a menu of thirty-six PHP class names.
                SelectFilter::make('subject')
                    ->label(__('admin.notifications.centre.subject'))
                    ->options(fn (): array => $this->subjectOptions())
                    ->query(function (Builder $query, array $data): Builder {
                        $subject = $data['value'] ?? null;

                        return $subject === null
                            ? $query
                            : $query->whereIn('type', $this->classesForSubject($subject));
                    }),

                Filter::make('recent')
                    ->label(__('admin.notifications.centre.last_7_days'))
                    ->query(fn (Builder $query): Builder => $query
                        ->where('created_at', '>=', CarbonImmutable::now()->subDays(7)->startOfDay())),
            ])
            ->toolbarActions([
                $this->bulkMarkReadAction(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('admin.notifications.centre.empty_heading'))
            ->emptyStateDescription(__('admin.notifications.centre.empty_body'))
            ->emptyStateIcon('heroicon-o-bell-slash')
            ->paginated([25, 50, 100])
            ->poll('60s');
    }

    /**
     * "Mark all as read" belongs in the page header, beside the title — it is about the whole
     * inbox, not about the rows a filter happens to be showing. Putting it in the table's header
     * would say the opposite, and would make it disappear whenever the table is empty.
     *
     * @return array<int, Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            $this->markAllReadAction(),
        ];
    }

    /**
     * The full alert, as an infolist modal. Reading it marks it read — the same contract as opening
     * a mail, and the reason the badge count means something.
     */
    protected function detailsAction(): Action
    {
        return Action::make('details')
            ->label(__('admin.notifications.centre.details'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->modalHeading(fn (DatabaseNotification $record): string => $record->data['title'] ?? __('admin.notifications.centre.details'))
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('admin.actions.close'))
            // The modal's own footer link is the deep link, so "read it" and "act on it" are one
            // gesture apart rather than two screens.
            ->extraModalFooterActions(fn (DatabaseNotification $record): array => array_filter([
                $this->linkUrl($record) === null ? null : Action::make('open_from_modal')
                    ->label($this->linkLabel($record))
                    ->icon('heroicon-o-arrow-top-right-on-square')
                    ->url($this->linkUrl($record)),
            ]))
            ->schema(fn (DatabaseNotification $record): array => [
                TextEntry::make('body')
                    ->hiddenLabel()
                    ->state($record->data['body'] ?? '—'),
                TextEntry::make('subject')
                    ->label(__('admin.notifications.centre.subject'))
                    ->state($this->subjectLabel($record->type))
                    ->badge(),
                TextEntry::make('received')
                    ->label(__('admin.notifications.centre.when'))
                    ->state($record->created_at?->translatedFormat('d/m/Y H:i') ?? '—')
                    ->helperText($record->created_at?->diffForHumans()),
            ])
            ->action(function (DatabaseNotification $record): void {
                abort_unless($this->owns($record), 403);

                $record->markAsRead();
            });
    }

    /** The deep link, as a one-click row action for readers who already know what it says. */
    protected function openAction(): Action
    {
        return Action::make('open')
            ->label(fn (DatabaseNotification $record): string => $this->linkLabel($record))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->url(fn (DatabaseNotification $record): ?string => $this->linkUrl($record))
            ->visible(fn (DatabaseNotification $record): bool => $this->linkUrl($record) !== null);
    }

    protected function readToggleAction(): Action
    {
        return Action::make('toggle_read')
            ->label(fn (DatabaseNotification $record): string => $record->read_at
                ? __('admin.notifications.centre.mark_unread')
                : __('admin.notifications.centre.mark_read'))
            ->icon(fn (DatabaseNotification $record): string => $record->read_at
                ? 'heroicon-o-envelope'
                : 'heroicon-o-envelope-open')
            ->color('gray')
            // A reader can only ever reach their own rows (the query is scoped to them), but the
            // record key arrives from the browser — so the ownership check is the gate, not the
            // query that produced the row.
            ->authorize(fn (DatabaseNotification $record): bool => $this->owns($record))
            ->action(function (DatabaseNotification $record): void {
                abort_unless($this->owns($record), 403);

                $record->read_at ? $record->markAsUnread() : $record->markAsRead();
            });
    }

    protected function markAllReadAction(): Action
    {
        return Action::make('mark_all_read')
            ->label(__('admin.notifications.centre.mark_all_read'))
            ->icon('heroicon-o-check-circle')
            ->color('gray')
            ->visible(fn (): bool => $this->unreadCount() > 0)
            ->authorize(fn (): bool => $this->reader() !== null)
            ->action(function (): void {
                abort_unless($this->reader() !== null, 403);

                $marked = $this->notificationsQuery()->whereNull('read_at')->update(['read_at' => now()]);

                Toast::make()
                    ->title(__('admin.notifications.centre.marked_read', ['count' => $marked]))
                    ->success()
                    ->send();
            });
    }

    protected function bulkMarkReadAction(): BulkAction
    {
        return BulkAction::make('mark_read')
            ->label(__('admin.notifications.centre.mark_read'))
            ->icon('heroicon-o-envelope-open')
            ->color('gray')
            ->deselectRecordsAfterCompletion()
            ->authorize(fn (): bool => $this->reader() !== null)
            ->action(function (Collection $records): void {
                abort_unless($this->reader() !== null, 403);

                // Re-scope rather than trusting the selection: the ids come from the browser.
                $marked = $this->notificationsQuery()
                    ->whereIn('id', $records->pluck('id'))
                    ->whereNull('read_at')
                    ->update(['read_at' => now()]);

                Toast::make()
                    ->title(__('admin.notifications.centre.marked_read', ['count' => $marked]))
                    ->success()
                    ->send();
            });
    }

    public function unreadCount(): int
    {
        return $this->reader() === null
            ? 0
            : $this->notificationsQuery()->whereNull('read_at')->count();
    }

    protected function owns(DatabaseNotification $record): bool
    {
        $reader = $this->reader();

        return $reader !== null
            && $record->notifiable_type === $reader->getMorphClass()
            && (string) $record->notifiable_id === (string) $reader->getAuthIdentifier();
    }

    /**
     * The destination stored on the row when it was written. Read back rather than recomputed: the
     * link is a fact about the moment the alert was raised, and recomputing it here would need the
     * notification object, which is long gone.
     */
    protected function linkUrl(DatabaseNotification $record): ?string
    {
        $url = $record->data['actions'][0]['url'] ?? null;

        // The centre's own URL is the fallback the channel writes when a notification has no record
        // to open. Offering it as a link FROM the centre is a link to the page you are reading.
        return filled($url) && ! str_contains((string) $url, static::getSlug()) ? $url : null;
    }

    protected function linkLabel(DatabaseNotification $record): string
    {
        return $record->data['actions'][0]['label'] ?? __('admin.notifications.actions.open');
    }

    /**
     * What an alert is about, in the reader's language — taken from the destination resource's own
     * model label so it always matches the screen the link opens.
     */
    protected function subjectLabel(string $notificationClass): string
    {
        $panel = Filament::getCurrentPanel()?->getId() ?? 'admin';
        $destination = NotificationTargets::destination($notificationClass, $panel)
            // A notification with no destination on THIS panel is still about something; borrow
            // the other panel's noun rather than filing it under "Other".
            ?? NotificationTargets::destination($notificationClass, $panel === 'admin' ? 'portal' : 'admin');

        $target = $destination[0] ?? null;

        if ($target === null) {
            return __('admin.notifications.centre.subject_other');
        }

        if (is_subclass_of($target, Page::class)) {
            return $target::getNavigationLabel();
        }

        /** @var class-string<resource> $target */
        return $target::getPluralModelLabel();
    }

    /** @return array<string, string> */
    protected function subjectOptions(): array
    {
        $options = [];

        foreach (NotificationTargets::registered() as $class) {
            $label = $this->subjectLabel($class);
            $options[$label] = $label;
        }

        asort($options);

        return $options;
    }

    /** @return array<int, class-string> */
    protected function classesForSubject(string $subject): array
    {
        return array_values(array_filter(
            NotificationTargets::registered(),
            fn (string $class): bool => $this->subjectLabel($class) === $subject,
        ));
    }
}
