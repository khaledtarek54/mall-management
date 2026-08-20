<?php

namespace App\Support;

/**
 * The PHP extensions this application needs at REQUEST time, and what breaks without each.
 *
 * ## Why this exists when composer already checks
 *
 * `composer.json` declares what the app itself calls, and the dependency tree declares the rest —
 * `filament/support` requires `ext-intl`, `mpdf/mpdf` requires `ext-gd`, `openspout` requires
 * `ext-zip`. So `composer install --no-dev`, which every deploy path runs, already refuses on a box
 * that is missing one. That is a real guard and this registry does not replace it.
 *
 * **What composer structurally cannot see is the SAPI split.** Composer runs under the CLI binary;
 * money columns render under FPM. A box can have `intl` compiled into `php-cli` and not into
 * `php-fpm` — a single missing `.ini` symlink does it — and then `composer install` succeeds, the
 * scheduler runs, `atriom:health` passes from the console, and every list, infolist and dashboard
 * showing money throws `RuntimeException` from `Number::currency()`. The deploy looks clean and the
 * panel is broken. This registry is read by {@see Health::checkPhpExtensions()}, which answers over
 * HTTP — in the SAPI that actually serves the request.
 *
 * ## What is in the list
 *
 * Only extensions whose absence breaks a FEATURE an operator would notice, each with the sentence
 * saying which. Extensions that are compiled into every mainstream PHP build (`json`, `pcre`,
 * `filter`, `hash`, `session`, `tokenizer`, `ctype`) are deliberately absent: listing them adds
 * rows nobody can act on and dilutes the ones that matter. `pdo_mysql` is absent for a different
 * reason — without it nothing connects, and `Health::checkDatabase()` already says so first.
 */
final class PhpExtensions
{
    /**
     * Extensions declared in `composer.json` because THIS application calls them directly.
     *
     * A subset of {@see REQUIRED}: the rest arrive through the dependency tree, and re-declaring a
     * dependency's requirement here would claim an ownership we do not have.
     *
     * @var array<int, string>
     */
    public const SELF_DECLARED = ['intl', 'mbstring', 'zip'];

    /**
     * extension => what an operator loses at request time without it.
     *
     * @var array<string, string>
     */
    public const REQUIRED = [
        'intl' => 'every money and numeric column throws — Number::currency() refuses without it, and the search fold silently stops matching «أحمد» to «احمد»',
        'mbstring' => 'text handling fails app-wide; Arabic is mangled rather than merely unsorted',
        'gd' => 'no PDF renders — mpdf needs it for every invoice, statement, payslip and purchase order',
        'zip' => 'no XLSX export, no owner pack, and backup:run writes nothing',
        'fileinfo' => 'uploads are rejected or mis-typed — the media library detects MIME through it',
        'exif' => 'image conversions fail, so tenant logos and unit photos never generate their thumbnails',
        'dom' => 'XLSX export and HTML sanitisation fail',
        'curl' => 'no outbound HTTP — the payment gateway, push notifications and error reporting all go silent',
        'openssl' => 'sessions, signed URLs and encrypted columns fail',
    ];

    /**
     * Which required extensions are missing from the SAPI asking the question.
     *
     * Lower-cased on both sides defensively: `get_loaded_extensions()` reports the names in this
     * list lower-case on every build checked, but the comparison costs nothing and a mixed-case
     * report would otherwise read as a missing extension.
     *
     * @param  array<int, string>|null  $loaded  injectable so the failure path is testable
     * @return array<string, string>
     */
    public static function missing(?array $loaded = null): array
    {
        $present = array_map('strtolower', $loaded ?? get_loaded_extensions());

        return array_filter(
            self::REQUIRED,
            fn (string $extension): bool => ! in_array(strtolower($extension), $present, true),
            ARRAY_FILTER_USE_KEY,
        );
    }
}
