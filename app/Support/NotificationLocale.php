<?php

namespace App\Support;

use App\Http\Middleware\SetLocale;
use App\Notifications\Channels\BellChannel;
use App\Support\Filament\LocalizedNotification;
use Filament\Pages\Page;
use Filament\Resources\Resource;
use Illuminate\Support\Facades\App;

/**
 * **A stored notification says what it says in BOTH languages, and is read in the reader's.**
 *
 * Every `toDatabase()` in this project builds its title and body with `__()`, which renders in
 * whatever locale is current *at the moment the alert is raised*. That is the wrong moment, and it
 * failed in both directions:
 *
 *   - a scheduled command has no session, so `config('app.locale')` decided — every overdue-invoice
 *     alert, SLA breach and expiring document reached an Arabic-only retailer in English;
 *   - a notification raised inside a request rendered in the SENDER's language, so an operator
 *     working in Arabic issued invoices whose alerts arrived in Arabic for tenants reading English.
 *
 * `HasLocalePreference` on the notifiables fixes the DELIVERED channels — Laravel wraps each
 * recipient's dispatch in `withLocale()`, so mail and push are rendered for the person receiving
 * them. But a bell entry is not delivered, it is **re-read**, potentially months later and after the
 * reader has changed language. A single rendered string cannot answer that; whichever locale it was
 * frozen in is the one it stays in.
 *
 * So the payload carries both. `BellChannel` runs the notification's own `toDatabase()` once per
 * supported locale — which is what makes NESTED translations come out right, the part that key +
 * parameter storage gets wrong: a body interpolating `__("admin.enums.maintenance_priority.urgent")`
 * would otherwise be an Arabic sentence with an English word inside it. Rendering the whole payload
 * under each locale cannot produce that, because nothing is interpolated across a language boundary.
 *
 * Read back through here at every surface that displays a notification: the bell
 * ({@see LocalizedNotification}), the notification centre, and the mobile API.
 *
 * Absent `i18n` — a row written before this shipped, or a notification that is not a bell alert —
 * the top-level `title`/`body` are returned untouched. Old rows keep working; they simply stay in
 * the language they were written in, which is the best that can be said about them.
 */
final class NotificationLocale
{
    /** The payload key holding the per-language renderings. */
    public const KEY = 'i18n';

    /** @return array<int, string> */
    public static function supported(): array
    {
        return SetLocale::SUPPORTED;
    }

    /**
     * The reader's language, clamped to one we actually have a catalogue for.
     */
    public static function current(?string $locale = null): string
    {
        $locale ??= App::getLocale();

        return in_array($locale, self::supported(), true)
            ? $locale
            : (string) config('app.locale');
    }

    /**
     * The payload as the reader should see it: title, body and the action's label swapped for the
     * ones rendered in their language, everything else untouched.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function localize(array $data, ?string $locale = null): array
    {
        $variant = self::variant($data, $locale);

        if ($variant === null) {
            return $data;
        }

        foreach (['title', 'body'] as $field) {
            if (array_key_exists($field, $variant)) {
                $data[$field] = $variant[$field];
            }
        }

        // The action's URL is the same in every language — only the noun in "Open invoice" changes,
        // so the label is swapped in place rather than the action being rebuilt.
        if (filled($variant['action_label'] ?? null) && isset($data['actions'][0])) {
            $data['actions'][0]['label'] = $variant['action_label'];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    public static function variant(array $data, ?string $locale = null): ?array
    {
        $variants = $data[self::KEY] ?? null;

        if (! is_array($variants) || $variants === []) {
            return null;
        }

        $locale = self::current($locale);

        // Fall back to the app default rather than to nothing: a catalogue that gained a third
        // language before the payload did should still render, in a language, not blank.
        $variant = $variants[$locale] ?? $variants[config('app.locale')] ?? reset($variants);

        return is_array($variant) ? $variant : null;
    }

    /**
     * The label on a bell entry's "Open …" action, in the CURRENT locale.
     *
     * Lives here rather than in {@see BellChannel} because two things
     * need it and they must not drift: the channel, writing the label when the alert is raised, and
     * `atriom:backfill-notification-locales`, writing it onto rows that predate the mechanism. The
     * noun comes from the destination resource's own model label, so it already matches the screen
     * the link opens, in whatever language is being asked for.
     *
     * @param  class-string  $notification
     */
    public static function openLabel(string $notification, ?string $panel): string
    {
        $destination = $panel ? NotificationTargets::destination($notification, $panel) : null;
        $target = $destination[0] ?? null;

        if ($target === null) {
            return __('admin.notifications.actions.open');
        }

        if (is_subclass_of($target, Page::class)) {
            return __('admin.notifications.actions.open_named', ['name' => $target::getNavigationLabel()]);
        }

        /** @var class-string<resource> $target */
        // No record behind the alert means the link lands on the list, so the label says so.
        $name = NotificationTargets::record($notification) === null
            ? $target::getPluralModelLabel()
            : $target::getModelLabel();

        return __('admin.notifications.actions.open_named', ['name' => $name]);
    }

    /**
     * Everything a display surface should show, stripped of the machinery that produced it. The
     * `i18n` block itself never leaves the server — it is how the answer was reached, not the
     * answer.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function forDisplay(array $data, ?string $locale = null): array
    {
        $localized = self::localize($data, $locale);

        unset($localized[self::KEY]);

        return $localized;
    }
}
