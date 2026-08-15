<?php

namespace App\Notifications\Channels;

use App\Support\Filament\AuthorizedAction;
use App\Support\NotificationBellAction;
use App\Support\NotificationLink;
use App\Support\NotificationLocale;
use Filament\Actions\Action;
use Filament\Resources\Resource;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\App;

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
 * The same seam carries the second half of the job: **the alert is stored in every language we
 * ship**, so a bell entry is read in the READER's language rather than frozen in whichever one
 * happened to be current when it was raised. See `translations()` below and
 * {@see NotificationLocale}.
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

        $data[NotificationLocale::KEY] = $this->translations($notification, $notifiable, $data);

        // Six payloads carried this dead key. Drop it on the way past so the stored row does not
        // keep advertising a mechanism that never existed.
        unset($data['url']);

        return $data;
    }

    /**
     * The alert rendered in every language we ship, stored beside the one it was raised in.
     *
     * `HasLocalePreference` already makes the DELIVERED channels — mail, push — render for the
     * person receiving them. A bell entry is different: it is not delivered, it is **re-read**,
     * possibly months later and possibly after the reader has switched language. One frozen string
     * cannot answer that.
     *
     * The whole `toDatabase()` is re-run under each locale rather than the translation KEY and its
     * parameters being stored. That is deliberate, and it is the part a key-plus-parameters design
     * gets wrong: bodies here interpolate values that are themselves translated —
     * `__("admin.enums.work_priority.{$priority}")`, a localized status, a formatted date —
     * so storing the parameters would produce an Arabic sentence with an English word inside it.
     * Re-rendering the payload cannot interpolate across a language boundary, because there is no
     * boundary to cross.
     *
     * Cost is two extra `toDatabase()` calls per notification. They are array builders over models
     * already in memory; the delivery this sits in front of is a database write.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, array<string, string|null>>
     */
    protected function translations(Notification $notification, object $notifiable, array $data): array
    {
        $translations = [];
        $current = App::getLocale();
        $actionName = $data['actions'][0]['name'] ?? null;

        try {
            foreach (NotificationLocale::supported() as $locale) {
                App::setLocale($locale);

                // The locale it was already rendered in needs no second pass.
                $payload = $locale === $current ? $data : parent::getData($notifiable, $notification);

                $translations[$locale] = [
                    'title' => $payload['title'] ?? null,
                    'body' => $payload['body'] ?? null,
                    'action_label' => match ($actionName) {
                        'open' => $this->openLabel($notification, $notifiable),
                        'details' => __('admin.notifications.actions.details'),
                        default => null,
                    },
                ];
            }
        } finally {
            // A `finally`, not a trailing call: a notification whose toDatabase() throws must not
            // leave the rest of the request rendering in Arabic because this was mid-loop.
            App::setLocale($current);
        }

        return $translations;
    }

    /**
     * Shared with the backfill so old rows get the same action new ones do — see
     * {@see NotificationBellAction}, which is where the reasoning lives.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function action(Notification $notification, object $notifiable, array $data): ?array
    {
        return NotificationBellAction::for($notification::class, $notifiable, $data);
    }

    /**
     * "Open invoice" beats "Open" — the reader learns where the click goes before making it, which
     * is the difference between a link and a leap. The noun comes from the destination resource's
     * own model label, so it is already translated and already matches the screen it opens.
     */
    protected function openLabel(Notification $notification, object $notifiable): string
    {
        return NotificationLocale::openLabel($notification::class, NotificationLink::panelFor($notifiable));
    }
}
