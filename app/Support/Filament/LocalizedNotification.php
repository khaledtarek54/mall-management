<?php

namespace App\Support\Filament;

use App\Notifications\Channels\BellChannel;
use App\Support\NotificationLocale;
use Filament\Notifications\Notification;

/**
 * **Filament's bell, reading each stored alert in the reader's language.**
 *
 * The bell renders a stored row through `Notification::fromDatabase()`, which hands the row's `data`
 * array to `fromArray()` and takes `title`/`body` from it verbatim. Those were written once, in
 * whatever locale was current when the alert was raised — a scheduled command's `config('app.locale')`,
 * or the language of whoever's request happened to trigger it. Neither has anything to do with the
 * person now reading the bell.
 *
 * `BellChannel` stores every language beside them. This is where one gets picked.
 *
 * ## Why this can be a container binding
 *
 * `Notification::make()` resolves through `app(static::class)`, and Filament's own `fromArray()`
 * anticipates the substitution explicitly:
 *
 *     // If the container constructs an instance of child class instead of the current class,
 *     // we should run `fromArray()` on the child class instead.
 *
 * So binding `Filament\Notifications\Notification` to this class in the container reaches every
 * render path — the bell dropdown, and any future one — without overriding Filament's Livewire
 * component or touching a view. The third use of this seam in the codebase, after
 * {@see AuthorizedAction} and {@see BellChannel}.
 *
 * Toasts are unaffected: a session toast's array has no `i18n` key, so `localize()` returns it
 * untouched, and it was rendered a moment ago in the reader's own request anyway.
 */
class LocalizedNotification extends Notification
{
    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data): static
    {
        return parent::fromArray(NotificationLocale::localize($data));
    }
}
