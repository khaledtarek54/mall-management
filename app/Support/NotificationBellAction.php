<?php

namespace App\Support;

use App\Notifications\Channels\BellChannel;
use Filament\Actions\Action;

/**
 * **The single action a bell entry carries — built in one place.**
 *
 * Two callers need it and they must not drift: {@see BellChannel},
 * attaching it as the alert is raised, and `atriom:backfill-notification-locales`, attaching it to
 * rows written before any of this existed. Those old rows are the ones an operator is looking at
 * today, so "clickable from now on" would have left the feature invisible on exactly the data in
 * front of them.
 *
 * One action, not two. A dropdown of twenty notifications each offering "Open" *and* "Details" is a
 * wall of buttons that reads as noise; the reader wants the one place that answers the alert. So:
 * the record when there is one, and the notification centre when there is not — which is what keeps
 * every entry clickable rather than only the ones that happen to own a row.
 */
final class NotificationBellAction
{
    /**
     * @param  class-string  $notification
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>|null
     */
    public static function for(string $notification, object $notifiable, array $payload): ?array
    {
        $url = NotificationLink::for($notification, $notifiable, $payload);

        if ($url !== null) {
            return Action::make('open')
                ->label(NotificationLocale::openLabel($notification, NotificationLink::panelFor($notifiable)))
                ->url($url)
                ->link()
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color($payload['color'] ?? 'primary')
                // Following the link answers the alert, so the badge should stop counting it.
                // Without this the operator clears a notification by acting on it and the bell
                // still says there are nine.
                ->markAsRead()
                ->toArray();
        }

        $centre = NotificationLink::centre($notifiable);

        if ($centre === null) {
            return null;
        }

        return Action::make('details')
            ->label(__('admin.notifications.actions.details'))
            ->url($centre)
            ->link()
            ->icon('heroicon-m-arrow-top-right-on-square')
            ->color('gray')
            ->markAsRead()
            ->toArray();
    }
}
