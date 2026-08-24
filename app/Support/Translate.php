<?php

namespace App\Support;

use Illuminate\Support\Facades\Lang;

/**
 * "Translate this, and if there is no translation show me THIS instead."
 *
 * ## The bug this exists to make impossible
 *
 * `__()` takes `(string $key, array $replace = [], ?string $locale = null)`. The third argument is
 * the **LOCALE**. Eight call sites across this app passed a fallback VALUE there:
 *
 *     __("admin.permission_modules.{$module}", [], static::humanize($module))
 *     __("admin.users.roles_list.{$state}", [], $state)
 *     __('admin.statuses.lease.'.$record->status, [], $record->status)
 *
 * The intent is obvious and the behaviour is not. Laravel is asked for the key in a locale called
 * *"Properties"* or *"technician"*, finds no such locale, and falls back to the **fallback locale**
 * — English. So the Arabic panel rendered the ENGLISH translation of a key it had a perfectly good
 * Arabic one for. The whole Roles & Permissions form was English in Arabic for this single reason:
 * every section heading and every module name, ~110 strings on one screen.
 *
 * And the fallback never worked either. When the key genuinely does not exist, `__()` returns the
 * KEY — so the "fallback" argument produced `admin.statuses.lease.foo` on screen rather than `foo`.
 * Both halves of the intent failed, which is why it survived: the common case rendered *something*
 * plausible, in the wrong language, on a screen most reviewers read in English.
 *
 * ## What this does instead
 *
 * Asks whether a translation exists, and uses the fallback when it does not.
 *
 * `Lang::has()` is called WITH its default fallback behaviour on purpose, which is the opposite of
 * what CLAUDE.md prescribes for parity CHECKS. The two want opposite things: a parity check asks
 * "is this key present in Arabic *specifically*" and must pass `fallback: false` or it passes for
 * every key present in English. RENDERING wants the widest net — an Arabic string if there is one,
 * otherwise the English string, and only then the raw value. Showing an operator `technician` when
 * a perfectly good English word exists would be a worse screen, not a more honest one.
 */
final class Translate
{
    /**
     * The translation for `$key`, or `$fallback` when no translation exists in any locale.
     *
     * @param  array<string, mixed>  $replace
     */
    public static function orFallback(string $key, string $fallback, array $replace = []): string
    {
        if (! Lang::has($key)) {
            return $fallback;
        }

        return (string) __($key, $replace);
    }

    /**
     * The translation for `$key`, or the value humanised, for a machine key like `tenant_sales`.
     *
     * The common shape of the bug above: a code catalogue whose entries mostly have translations,
     * and a readable last resort for the one that does not.
     */
    public static function orHumanized(string $key, string $value): string
    {
        return self::orFallback($key, ucwords(str_replace(['_', '-'], ' ', $value)));
    }
}
