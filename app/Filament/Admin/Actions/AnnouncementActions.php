<?php

namespace App\Filament\Admin\Actions;

use App\Filament\Admin\Resources\Announcements\AnnouncementResource;
use App\Services\SendAnnouncementAction;
use App\Support\RowActionPolicy;
use Filament\Actions\Action;
use Filament\Notifications\Notification;

/**
 * **Everything you can DO to a announcement, defined once.**
 *
 * `send` lived inline in `AnnouncementsTable`,
 * so the act was reachable from the LIST and the record's
 * own page carried Delete and little else — backwards from the record-hub architecture this
 * project took from Yardi: **the list finds, the record acts**. Defined here, composed onto the
 * record page, so the two surfaces can never drift.
 *
 * Safe to move, and measured rather than assumed: every role that can perform this act can open
 * the page it moved to. Four resources failed that check — an act held by a role that
 * deliberately lacks `{module}.edit` — and kept their verbs on the row; see
 * {@see RowActionPolicy}.
 */
class AnnouncementActions
{
    /**
     * @return array<int, Action>
     */
    public static function all(): array
    {
        return [
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
        ];
    }
}
