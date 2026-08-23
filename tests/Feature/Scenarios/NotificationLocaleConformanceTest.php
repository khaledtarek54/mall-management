<?php

/*
|--------------------------------------------------------------------------
| Conformance gate — no notification is written in one language
|--------------------------------------------------------------------------
| Two notifications shipped with their prose typed straight into the PHP —
| `'title' => 'New owner request'` and `'title' => 'Message from '.$label`.
| Neither had a key, so neither had an Arabic version, so neither could ever
| render in the reader's language however the rest of the machinery behaved.
| Nothing caught it: they are perfectly ordinary-looking lines, and the bell
| showed them happily.
|
| This gate reads every notification's toDatabase() and refuses a `title` or
| `body` that is neither a translation call nor operator-entered content. It
| also holds the two structural halves of the mechanism in place:
|
|   - every notifiable declares HasLocalePreference, without which Laravel
|     renders a DELIVERED notification (mail, push) in whatever locale the
|     sender happened to be in;
|   - both language catalogues answer every notification key, so a key added
|     to one file and not the other cannot reach production as a raw
|     `admin.notifications.…` string on somebody's screen.
*/

use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\User;
use App\Support\NotificationLocale;
use App\Support\NotificationTargets;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;
use Tests\Support\NotificationCatalogue;

/**
 * The expression each of `title` / `body` is assigned in a notification's toDatabase().
 *
 * @return array<string, string>
 */
function payloadExpressions(string $notification): array
{
    $body = NotificationCatalogue::methodBody($notification, 'toDatabase');

    if ($body === null) {
        return [];
    }

    $found = [];

    foreach (['title', 'body'] as $field) {
        // From `'title' =>` to the next key at the same depth (12 spaces + a quote) or the array's
        // close. Good enough to see whether a translation call is in there; this is a smell test,
        // not a parser.
        if (preg_match("/'{$field}' => (.*?)(?=\n            '|\n        \];)/s", $body, $m)) {
            $found[$field] = $m[1];
        }
    }

    return $found;
}

it('never writes a notification\'s prose straight into the PHP', function () {
    $offenders = [];

    foreach (NotificationTargets::registered() as $notification) {
        foreach (payloadExpressions($notification) as $field => $expression) {
            // A translation call anywhere in the expression is the whole point — ternaries over two
            // keys are fine.
            if (str_contains($expression, '__(')) {
                continue;
            }

            // Otherwise it must be pure data: an announcement's own title, a tenant's message. Those
            // are operator-entered content in whatever language the operator wrote them, and it is
            // not ours to translate. A quoted word with letters in it, though, is prose.
            $literals = preg_replace('/\$[A-Za-z_>\-\[\]\'"$\w():]*/', '', $expression);

            if (preg_match("/'[^']*[A-Za-z]{2,}[^']*'/", (string) $literals)) {
                $offenders[] = class_basename($notification).'::'.$field.' → '.trim(preg_replace('/\s+/', ' ', $expression), " ,\n");
            }
        }
    }

    expect($offenders)->toBe([],
        'These notifications write untranslatable prose into their payload. Move it to a key in '
        ."lang/en/admin.php + lang/ar/admin.php and call __():\n  ".implode("\n  ", $offenders));
});

it('never writes an EMAIL\'s prose straight into the PHP either', function () {
    // The gate above reads toDatabase(), which is why it did not see the one notification that has
    // no bell entry at all: TenantResetPasswordNotification was four hard-coded English sentences,
    // and it reaches a locked-out retailer at the one moment they cannot switch the interface
    // language to understand it. Mail is a channel like any other.
    //
    // Every notification class, not just the ones in NotificationTargets — a mail-only notification
    // is exactly the kind that escapes a bell-shaped register.
    $offenders = [];

    foreach (NotificationCatalogue::classes() as $notification) {
        $body = NotificationCatalogue::methodBody($notification, 'toMail');

        if ($body === null) {
            continue;
        }

        // The three MailMessage calls that put words in front of a reader. `->markdown()` is
        // excluded on purpose: its string is a view name, not prose.
        preg_match_all('/->(subject|line|action)\((.*?)\)\s*(?:->|;)/s', $body, $calls, PREG_SET_ORDER);

        foreach ($calls as [, $method, $argument]) {
            // `DocumentText::for()` / `::forSubject()` is the THIRD locale-aware resolver, added by
            // EG-15: it reads the operator's own wording for the document's property — both
            // languages on one row, picked at render time — and falls back to the very translation
            // key the notification used before. Trusted on the same terms as `__()`, and with the
            // same stated limitation: once a resolver is named, the whole argument is trusted, so a
            // hardcoded English fallback beside it would not be caught here.
            if (str_contains($argument, '__(')
                || str_contains($argument, 'Lang::get')
                || str_contains($argument, 'DocumentText::for')) {
                continue;
            }

            if (preg_match("/'[^']*[A-Za-z]{2,}[^']*'/", $argument)) {
                $offenders[] = class_basename($notification)."::toMail() {$method}(".trim(preg_replace('/\s+/', ' ', $argument)).')';
            }
        }
    }

    expect($offenders)->toBe([],
        "These email lines are typed in English and can never be anything else:\n  "
        .implode("\n  ", $offenders));
});

it('translates the framework\'s own mail chrome', function () {
    // Our keys only cover the words we write. "Hello!", "Regards," and the "trouble clicking the
    // button" subcopy come from Laravel's notification layout and its built-in ResetPassword — via
    // Lang::get() against lang/{locale}.json, which did not exist. So an alert with a perfectly
    // translated body arrived wrapped in English, and the /admin and /portal password resets (which
    // use Laravel's notification, not ours) were English end to end.
    //
    // Read out of the vendor views rather than listed here, so a Laravel upgrade that rewords one
    // turns this red instead of silently reverting a sentence to English.
    $arabic = json_decode(file_get_contents(lang_path('ar.json')), true);

    expect($arabic)->toBeArray('lang/ar.json is missing — the mail chrome falls back to English.');

    $sources = [
        base_path('vendor/laravel/framework/src/Illuminate/Notifications/resources/views/email.blade.php'),
        base_path('vendor/laravel/framework/src/Illuminate/Auth/Notifications/ResetPassword.php'),
    ];

    $missing = [];

    foreach ($sources as $path) {
        if (! is_file($path)) {
            continue;
        }

        preg_match_all("/(?:@lang|Lang::get|__)\('((?:[^'\\\\]|\\\\.)+)'/", file_get_contents($path), $matches);

        foreach ($matches[1] as $string) {
            $key = stripcslashes($string);

            if (! array_key_exists($key, $arabic)) {
                $missing[] = $key;
            }
        }
    }

    expect(array_values(array_unique($missing)))->toBe([],
        'Laravel renders these in every notification email and lang/ar.json has no Arabic for them, '
        ."so an Arabic reader gets an English wrapper around a translated body:\n  "
        .implode("\n  ", array_unique($missing)));
});

it('gives every notifiable a language preference to be addressed in', function () {
    // Without this Laravel has nothing to pass to withLocale(), so a mailed alert renders in the
    // SENDER's language — or, from a scheduled command, in the app default for everybody.
    foreach ([User::class, TenantUser::class, Tenant::class] as $notifiable) {
        expect(is_subclass_of($notifiable, HasLocalePreference::class))->toBeTrue(
            class_basename($notifiable).' is a notification recipient but declares no preferred '
            .'locale, so everything delivered to it renders in whoever\'s language sent it.');

        expect(Schema::hasColumn((new $notifiable)->getTable(), 'locale'))->toBeTrue(
            class_basename($notifiable).' has nowhere to store the preference it promises.');
    }
});

it('answers every notification key in both languages', function () {
    $en = require lang_path('en/admin.php');
    $ar = require lang_path('ar/admin.php');

    $missing = array_keys(array_diff_key($en['notifications'] ?? [], $ar['notifications'] ?? []));
    $extra = array_keys(array_diff_key($ar['notifications'] ?? [], $en['notifications'] ?? []));

    expect($missing)->toBe([], 'Missing from lang/ar/admin.php: '.implode(', ', $missing));
    expect($extra)->toBe([], 'Missing from lang/en/admin.php: '.implode(', ', $extra));
});

it('actually says something different in Arabic', function () {
    // A catalogue can be "complete" and still be English twice over — the commonest way a
    // translation ships without being one. Sampled across the notification keys that are real
    // sentences rather than pure placeholder strings like ":reference: :subject".
    $en = require lang_path('en/admin.php');
    $ar = require lang_path('ar/admin.php');

    $untranslated = [];

    foreach ($en['notifications'] ?? [] as $key => $value) {
        if (! is_string($value)) {
            continue;
        }

        // Placeholder NAMES are English by construction (`:reference`, `:days`) and are supposed to
        // be byte-identical in both files — they are substitution points, not prose. Strip them
        // before asking whether what is left is a sentence, or a string like ':reference: :subject'
        // reads as untranslated English when it is the same in every language on purpose.
        $prose = preg_replace('/:[a-z_]+/', '', $value);

        if (! preg_match('/[A-Za-z]{3,}/', (string) $prose)) {
            continue;
        }

        $arabic = $ar['notifications'][$key] ?? null;

        // Same string, and it contains real words → nobody translated it.
        if (is_string($arabic) && $arabic === $value) {
            $untranslated[] = $key;
        }
    }

    expect($untranslated)->toBe([],
        'These notification strings are byte-identical in both catalogues — English wearing an '
        .'Arabic key: '.implode(', ', $untranslated));
});

it('stores every language it claims to support', function () {
    // The payload's `i18n` block is keyed by SetLocale::SUPPORTED. Adding a language to that list
    // without the catalogue behind it would store a variant that renders as raw keys.
    foreach (NotificationLocale::supported() as $locale) {
        expect(is_file(lang_path("{$locale}/admin.php")))->toBeTrue(
            "Locale [{$locale}] is offered to readers but has no admin catalogue.");
    }

    // And the reader's locale is clamped to one we have, rather than trusted.
    App::setLocale('fr');
    expect(NotificationLocale::current())->toBe(config('app.locale'));
    App::setLocale('ar');
    expect(NotificationLocale::current())->toBe('ar');
});
