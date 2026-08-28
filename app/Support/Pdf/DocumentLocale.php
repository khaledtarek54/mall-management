<?php

namespace App\Support\Pdf;

use App\Http\Middleware\SetLocale;
use App\Support\NotificationLocale;
use Closure;
use Illuminate\Support\Facades\App;

/**
 * **A document is written in the READER's language, not the sender's.**
 *
 * Every PDF this system produced rendered in `app()->getLocale()` — the language of whoever pressed
 * the button. That is the wrong question twice over:
 *
 *   - an operator working the panel in Arabic issued an ARABIC invoice to a retailer whose
 *     accountant files in English, and an operator in English did the reverse — the document a
 *     tenant hands to their own accountant, in a language chosen by a stranger's UI preference;
 *   - a scheduled run has no session at all, so `config('app.locale')` decided: every invoice
 *     e-mailed by the monthly billing run reached every tenant in the app default, whatever
 *     language they had told us they read.
 *
 * The same defect {@see NotificationLocale} was written for, on the surface that
 * matters more: a bell entry is a nudge, an invoice is evidence.
 *
 * So a document's language is RESOLVED, from the most specific answer available down to the app
 * default, and every PDF service takes it as an argument rather than reading the ambient locale:
 *
 *   1. what the operator ASKED for on the download modal — they are looking at the recipient and
 *      may know something the stored preference does not (an Egyptian tenant whose auditor is
 *      foreign, a landlord's lawyer who asked for the English copy);
 *   2. the RECIPIENT's own stored `locale` — the tenant, employee, owner or vendor the document is
 *      addressed to, which is the right default and the one the e-mail attachment must use because
 *      no one is there to choose;
 *   3. whatever the current request is in, so an operator generating an internal report gets the
 *      language they are reading the panel in;
 *   4. `config('app.locale')`.
 *
 * Each tier is CLAMPED to {@see SetLocale::SUPPORTED} rather than trusted. A stored `locale` is a
 * five-character column that an importer or an older release could have put anything in, and an
 * unsupported value does not fail loudly — `__()` silently falls through to the fallback locale, so
 * the document renders in English while every other signal says Arabic. Clamping is also what stops
 * a request parameter selecting a language catalogue that does not exist.
 *
 * `SetLocale::SUPPORTED` is the ONE list, not a second copy here: the languages a document can be
 * written in are the languages the app has a catalogue for, and two lists is how one of them ends
 * up missing the third language on the day it is added.
 */
final class DocumentLocale
{
    /**
     * The languages written right-to-left.
     *
     * A list rather than `=== 'ar'`, which is what all thirteen PDF services asked and what a third
     * RTL language (Farsi, Urdu, Hebrew) would have had to be added to in thirteen places.
     */
    public const RTL = ['ar'];

    /** @return array<int, string> */
    public static function supported(): array
    {
        return SetLocale::SUPPORTED;
    }

    /**
     * The language this document should be written in.
     *
     * @param  string|null  $requested  what the operator picked on the download modal, if anything
     * @param  object|null  $recipient  the party the document is addressed to — anything with a
     *                                  `preferredLocale()` (Tenant, TenantUser, User) or a `locale`
     *                                  attribute. Null for a document with no counterparty, such as
     *                                  a trial balance.
     */
    public static function resolve(?string $requested = null, ?object $recipient = null): string
    {
        foreach ([$requested, self::preferenceOf($recipient), App::getLocale(), config('app.locale')] as $candidate) {
            if (is_string($candidate) && in_array($candidate, self::supported(), true)) {
                return $candidate;
            }
        }

        // Only reachable if `app.locale` itself is unsupported, which is a misconfiguration rather
        // than a state to render in — but a document with no language is not a document, so take
        // the first thing we have a catalogue for.
        return self::supported()[0];
    }

    /** Whether the given (or current) language reads right-to-left. */
    public static function isRtl(?string $locale = null): bool
    {
        return in_array($locale ?? App::getLocale(), self::RTL, true);
    }

    /**
     * The options for a download modal's language picker: value => the language's name IN ITSELF.
     *
     * «العربية», not "Arabic" — a picker that names a language in the language you are trying to
     * leave is unreadable to precisely the person who needs it. Filament resolves an option label
     * once at render, so this is a plain array; the labels are deliberately not translated, because
     * a language's endonym is the same string whichever locale the panel is in.
     *
     * @return array<string, string>
     */
    public static function options(): array
    {
        $endonyms = ['en' => 'English', 'ar' => 'العربية'];

        return collect(self::supported())
            ->mapWithKeys(fn (string $locale): array => [$locale => $endonyms[$locale] ?? strtoupper($locale)])
            ->all();
    }

    /**
     * Run a callback with the application in the document's language, restoring it afterwards.
     *
     * The locale has to be set around the whole render and not only around the template, because
     * these documents resolve half their content BEFORE the blade runs — `CamStatementPdfService`
     * picks `name_ar` over `name_en` while building its facts, `IssuingEntity::forView()` reads
     * settings, and every service composes `__()`-derived labels into its view data. A wrapper that
     * only covered `View::make()` would produce a document whose body was Arabic and whose column
     * headings were English, which is worse than either language alone.
     *
     * `finally`, not a trailing restore: a template that throws must not leave the rest of the
     * request rendering in the recipient's language. That is how an operator presses Download,
     * sees a refusal toast, and finds their panel has silently switched to Arabic.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function in(string $locale, Closure $callback): mixed
    {
        $previous = App::getLocale();

        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    /**
     * A recipient's stored language, however that model spells it.
     *
     * `preferredLocale()` is Laravel's own `HasLocalePreference` contract and the three notifiables
     * implement it; a Vendor or an Employee carries the column without the interface. Asking for
     * both means a model growing the preference is served without editing this list.
     */
    private static function preferenceOf(?object $recipient): ?string
    {
        if ($recipient === null) {
            return null;
        }

        if (method_exists($recipient, 'preferredLocale')) {
            $locale = $recipient->preferredLocale();

            if (filled($locale)) {
                return (string) $locale;
            }
        }

        $locale = data_get($recipient, 'locale');

        return filled($locale) ? (string) $locale : null;
    }
}
