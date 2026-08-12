<?php

namespace App\Notifications\Channels;

use App\Support\Filament\AuthorizedAction;
use App\Support\NotificationLink;
use App\Support\NotificationTargets;
use Filament\Actions\Action;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;

/**
 * **The one seam that makes every bell notification clickable.**
 *
 * Thirty-six notification classes write a `toDatabase()` payload. None of them carried an
 * `actions` key, so the bell rendered thirty-six dead ends: an operator reading "SLA breached on
 * WO-0042" had to remember the module, switch to the right property, find the filter and type the
 * reference. Six payloads carried a hand-written `'url' => null` that nothing has ever read —
 * which is the shape this replaces.
 *
 * The fix is deliberately NOT thirty-six edits. Laravel resolves the `database` channel through
 * the container (`ChannelManager::createDatabaseDriver()` → `$container->make(DatabaseChannel)`),
 * so binding this subclass there attaches the destination to every notification at once, and to
 * every one added later without anyone remembering to. Same play as the
 * `Filament\Actions\Action` → {@see AuthorizedAction} binding.
 *
 * Because it sits in the CHANNEL and not in `toDatabase()`, three things stay true for free:
 *
 *  - **Push is unaffected.** {@see PushChannel} calls `$notification->toDatabase()` itself and
 *    never passes through here, so a web URL cannot leak into an FCM payload the mobile app would
 *    have to ignore.
 *  - **The reader is known.** `$notifiable` is in scope, which is the only reason a panel-correct
 *    URL is possible at all — the same notification object is delivered to an operator and to a
 *    retailer, and `/admin` and `/portal` are not interchangeable.
 *  - **Nothing else changes.** Payload keys, titles, bodies, `format`, `duration` — all still
 *    written by the notification, and all still what the mail and push channels read.
 *
 * One accepted limitation, inherited rather than introduced: the action LABEL is translated when
 * the notification is sent, not when it is read — exactly like the `title` and `body` beside it,
 * which every notification already builds with `__()`. A stored notification is a snapshot in the
 * locale it was raised in.
 */
class BellChannel extends DatabaseChannel
{
    /**
     * @param  mixed  $notifiable
     * @return array<string, mixed>
     */
    protected function getData($notifiable, Notification $notification)
    {
        /** @var array<string, mixed> $data */
        $data = parent::getData($notifiable, $notification);

        // Only Filament's bell renders actions, and it only renders payloads tagged `filament`.
        // Anything else on the database channel is a plain record — leave it exactly as written.
        if (($data['format'] ?? null) !== 'filament') {
            return $data;
        }

        // A notification that already states its own actions has thought about it harder than a
        // registry can; never overwrite that.
        if (filled($data['actions'] ?? null)) {
            return $data;
        }

        $action = $this->action($notification, $notifiable, $data);

        if ($action !== null) {
            $data['actions'] = [$action];
        }

        // Six payloads carried this dead key. Drop it on the way past so the stored row does not
        // keep advertising a mechanism that never existed.
        unset($data['url']);

        return $data;
    }

    /**
     * The single action a bell entry carries.
     *
     * One, not two. A dropdown of twenty notifications each offering "Open" *and* "Details" is a
     * wall of buttons that reads as noise; the reader wants the one place that answers the alert.
     * So: the record when there is one, and the notification centre when there is not — which is
     * what keeps every entry clickable rather than only the ones that happen to own a row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function action(Notification $notification, object $notifiable, array $data): ?array
    {
        $url = NotificationLink::for($notification, $notifiable, $data);

        if ($url !== null) {
            return Action::make('open')
                ->label($this->openLabel($notification, $notifiable))
                ->url($url)
                ->link()
                ->icon('heroicon-m-arrow-top-right-on-square')
                ->color($data['color'] ?? 'primary')
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

    /**
     * "Open invoice" beats "Open" — the reader learns where the click goes before making it, which
     * is the difference between a link and a leap. The noun comes from the destination resource's
     * own model label, so it is already translated and already matches the screen it opens.
     */
    protected function openLabel(Notification $notification, object $notifiable): string
    {
        $panel = NotificationLink::panelFor($notifiable);
        $destination = $panel ? NotificationTargets::destination($notification::class, $panel) : null;
        $target = $destination[0] ?? null;

        if ($target === null) {
            return __('admin.notifications.actions.open');
        }

        if (is_subclass_of($target, Page::class)) {
            return __('admin.notifications.actions.open_named', ['name' => $target::getNavigationLabel()]);
        }

        /** @var class-string<resource> $target */
        // No record behind the alert means the link lands on the list, so the label says so.
        $name = NotificationTargets::record($notification::class) === null
            ? $target::getPluralModelLabel()
            : $target::getModelLabel();

        return __('admin.notifications.actions.open_named', ['name' => $name]);
    }
}
