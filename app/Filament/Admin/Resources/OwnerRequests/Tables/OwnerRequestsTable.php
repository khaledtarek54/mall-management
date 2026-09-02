<?php

namespace App\Filament\Admin\Resources\OwnerRequests\Tables;

use App\Filament\Admin\Resources\OwnerRequests\OwnerRequestResource;
use App\Models\OwnerRequest;
use App\Services\OwnerRequestService;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

class OwnerRequestsTable
{
    /** The original request plus every reply, attributed + timestamped — the conversation, in order. */
    /**
     * May the signed-in user answer this thread?
     *
     * **Two ways in, and only one of them existed.** An OPERATOR replies because they hold
     * `owner_requests.edit` — that is the inbox. The RAISER replies because it is their own
     * conversation: `owner` holds `.view` and `.create` and deliberately not `.edit`, and
     * `OwnerRequestResource::getEloquentQuery()` already narrows them to
     * `created_by_user_id = me`, so "it is mine" is a fact the scope has established rather than a
     * new grant. Without this the owner could raise a request, be answered, and neither read the
     * answer nor respond — while `OwnerRequestService::notifyCounterparty()` was already written to
     * bell "the operator team when the owner replies".
     *
     * Named once because `visible()`, `authorize()` and the `action()` guard must not drift — the
     * project rule for every write action.
     */
    private static function mayReply(OwnerRequest $request): bool
    {
        if ($request->isTerminal()) {
            return false;
        }

        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return OwnerRequestResource::canEdit($request)
            || (int) $request->created_by_user_id === (int) $user->id;
    }

    private static function renderThread(OwnerRequest $request): HtmlString
    {
        $line = function (?string $who, $when, string $body): string {
            $meta = e(($who ?: __('admin.owner_requests.unknown_author')).' · '.($when?->diffForHumans() ?? ''));
            $text = nl2br(e($body));

            return "<div style=\"margin-bottom:.6rem;\"><div style=\"font-size:.75rem;color:#6b7280;\">{$meta}</div><div>{$text}</div></div>";
        };

        // The opening message is the request itself (subject + body) from its creator.
        $html = $line($request->creator?->name, $request->created_at, $request->subject."\n".$request->body);

        foreach ($request->replies as $reply) {
            $html .= $line($reply->author?->name, $reply->created_at, $reply->body);
        }

        return new HtmlString($html);
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')
                    ->label(__('admin.tables.owner_request.reference'))
                    ->searchable()
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('subject')
                    ->label(__('admin.tables.owner_request.subject'))
                    ->searchable()
                    ->limit(40)
                    ->weight('medium'),
                TextColumn::make('creator.name')
                    ->label(__('admin.tables.owner_request.owner'))
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.tables.owner_request.property'))
                    ->placeholder('—'),
                TextColumn::make('priority')
                    ->label(__('admin.tables.owner_request.priority'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.owner_requests.priorities.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'high' => 'danger',
                        'medium' => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('status')
                    ->label(__('admin.tables.owner_request.status'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.owner_requests.statuses.{$state}"))
                    ->color(fn (string $state) => match ($state) {
                        'open' => 'info',
                        'in_progress' => 'primary',
                        'resolved' => 'success',
                        'closed' => 'gray',
                        'cancelled' => 'danger',
                        default => 'gray',
                    }),
                // How lively the conversation is — the number of replies since it was raised.
                TextColumn::make('replies_count')
                    ->label(__('admin.owner_requests.replies'))
                    ->counts('replies')
                    ->badge()
                    ->color(fn (int $state) => $state > 0 ? 'primary' : 'gray')
                    ->alignCenter(),
                TextColumn::make('created_at')
                    ->label(__('admin.tables.owner_request.created'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.tables.owner_request.status'))
                    ->options(fn () => collect(OwnerRequest::STATUSES)
                        ->mapWithKeys(fn ($s) => [$s => __("admin.owner_requests.statuses.{$s}")])),
                TrashedFilter::make(),
            ])
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => OwnerRequestResource::canView($record))
                    ->authorize(fn ($record) => OwnerRequestResource::canView($record)),
                // **READING THE CONVERSATION IS NOT AN EDIT, AND ONE PARTY COULD DO NEITHER.**
                // Module 15 built a two-way thread — `OwnerRequestService::notifyCounterparty()`
                // says so in writing, bellling "the operator team when the owner replies" — and the
                // only surface onto it was the reply modal below, gated on `canEdit()`. The `owner`
                // role holds `owner_requests.view` and `.create` and deliberately NOT `.edit`, so
                // Jawad could raise a request, be answered, and never see the answer or respond to
                // it. The mechanism was complete and the door was missing: the shape this codebase
                // keeps finding.
                //
                // A read of the thread, for whoever may VIEW the record. It carries no write of any
                // kind, so it is safe for the view-only oversight roles the owner is one of.
                Action::make('conversation')
                    ->label(__('admin.owner_requests.actions.conversation'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('gray')
                    ->modalHeading(fn (OwnerRequest $r) => $r->reference.' · '.$r->subject)
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('admin.owner_requests.actions.close'))
                    // Only where it says something the row does not: a request nobody has answered
                    // is its own opening message, already on screen.
                    ->visible(fn (OwnerRequest $r) => OwnerRequestResource::canView($r)
                        && $r->replies()->exists())
                    ->authorize(fn (OwnerRequest $r) => OwnerRequestResource::canView($r))
                    ->schema([
                        Placeholder::make('thread')
                            ->label(__('admin.owner_requests.conversation'))
                            ->content(fn (OwnerRequest $r) => self::renderThread($r)),
                    ]),
                // A REPLY, not a one-shot note. Shows the whole conversation inline, takes a message
                // (always saved — the old flow dropped it unless status happened to be "resolved"),
                // and lets an optional status move ride along. The other party is notified.
                Action::make('reply')
                    ->label(__('admin.owner_requests.actions.reply'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->color('primary')
                    ->modalHeading(fn (OwnerRequest $r) => $r->reference.' · '.$r->subject)
                    ->modalSubmitActionLabel(__('admin.owner_requests.actions.send'))
                    ->visible(fn (OwnerRequest $r) => self::mayReply($r))
                    // A reply is published to the counterparty and can transition the request — gate
                    // it, don't just hide it (the module-15 close-out logged this as a LOW nit).
                    ->authorize(fn (OwnerRequest $r) => self::mayReply($r))
                    ->fillForm(fn (OwnerRequest $r) => ['status' => $r->status])
                    ->schema([
                        // The conversation so far — the original message + every reply, attributed and
                        // timestamped, so a reply is written in context, not blind.
                        Placeholder::make('thread')
                            ->label(__('admin.owner_requests.conversation'))
                            ->content(fn (OwnerRequest $r) => self::renderThread($r)),
                        Textarea::make('body')
                            ->label(__('admin.owner_requests.actions.your_reply'))
                            ->rows(3)
                            ->required(),
                        // **Moving the status is the OPERATOR's act, not the raiser's.** An owner
                        // answering a question must not be able to mark their own request resolved
                        // — that is the operator saying the work is done. Hidden AND refused: a
                        // hidden Select still arrives in the Livewire payload, so the action drops
                        // it rather than trusting the form.
                        Select::make('status')
                            ->label(__('admin.owner_requests.set_status'))
                            ->helperText(__('admin.owner_requests.set_status_hint'))
                            ->visible(fn (OwnerRequest $r) => OwnerRequestResource::canEdit($r))
                            ->options(fn () => collect(OwnerRequest::STATUSES)
                                ->mapWithKeys(fn ($s) => [$s => __("admin.owner_requests.statuses.{$s}")]))
                            ->native(false),
                    ])
                    ->action(function (OwnerRequest $r, array $data) {
                        abort_unless(self::mayReply($r), 403);

                        // The status rides along only for whoever may actually move it.
                        $status = OwnerRequestResource::canEdit($r) ? ($data['status'] ?? null) : null;

                        try {
                            app(OwnerRequestService::class)->reply($r, Auth::user(), $data['body'], $status);
                        } catch (\DomainException $e) {
                            Notification::make()->title($e->getMessage())->danger()->send();

                            return;
                        }

                        // Feedback with the resulting state — the reply is on the thread and the
                        // status it now sits at.
                        Notification::make()
                            ->title(__('admin.owner_requests.notices.replied'))
                            ->body(__('admin.owner_requests.notices.replied_body', [
                                'status' => __("admin.owner_requests.statuses.{$r->refresh()->status}"),
                            ]))
                            ->success()
                            ->send();
                    }),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateIcon('heroicon-o-inbox-arrow-down')
            ->emptyStateHeading(__('admin.empty.owner_requests.heading'))
            ->emptyStateDescription(__('admin.empty.owner_requests.description'));
    }
}
