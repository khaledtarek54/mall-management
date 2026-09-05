<?php

namespace App\Support;

use App\Models\ViolationCategory;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/**
 * What a LINE on a money document says, resolved when it is read (UX-30).
 *
 * The third instalment of the rule {@see JournalNarrative} states for the ledger and
 * {@see LeaseEventNarrative} for the lease timeline: **a row stores DATA, never PROSE.** What
 * neither reached is the line text on the documents a TENANT reads — the invoice and the credit
 * note they file with their own accountant.
 *
 * Two failures lived here and they look different from inside the services that had them.
 *
 * **Composed with `__()` at WRITE time.** The late fee, the utility recharge, the returned-cheque
 * fee, the deposit and the violation fine each resolved a perfectly good lang key at the moment the
 * line was raised — so the sentence froze in whichever language that run happened to be in. On a
 * billing night that is `config('app.locale')`; on an operator's click it is whatever they had the
 * panel set to. The Arabic translation of that line already existed and could never reach the
 * tenant unless the operator was in Arabic when they billed.
 *
 * **Never translatable at all.** `MonthlyBillingService` built its label as
 * `"{$charge->name} - {$month}"` and appended raw-English ` (in arrears)` and ` (75% pro-rated)`,
 * with the month from `format('F Y')`, which is not localised. That is on every monthly invoice in
 * the portfolio.
 *
 * ## The stored prose is the FLOOR, not the truth
 *
 * `description` stays and is still written. Every line raised before this has prose and no key; an
 * operator may type their own text into the invoice form, and that is their words, not a template's
 * — so **editing the description clears the key**, the same precedence `LeaseEventNarrative` gives
 * an operator's own reason. A reader nobody converted degrades to today's wording rather than to a
 * blank cell, because a money document with an unnamed line is worse than one in the wrong language.
 *
 * ## The whole line is ONE template, never a stem plus suffixes
 *
 * `billing.period_arrears_prorated` is a key of its own rather than three fragments joined at read
 * time. Arabic does not put a parenthetical where English does, so composing suffixes in the
 * reader's locale is the same defect as composing them in the writer's — it just moves where the
 * sentence breaks.
 *
 * ## A DATE placeholder is formatted in the reader's locale
 *
 * Storing `"September 2026"` as data would carry the writer's language inside the reader's sentence
 * — an English month in an Arabic line, which is exactly what this replaces. A month or date
 * placeholder stores an ISO date and is rendered here, so the same row reads «سبتمبر ٢٠٢٦» and
 * "September 2026" from one stored value.
 */
final class LineNarrative
{
    /**
     * Every narrative a document line may carry.
     *
     * `lang` is where the wording lives — five of these are the keys their services were ALREADY
     * resolving at write time, reused rather than re-invented, so the operator's existing Arabic
     * is what a converted line starts rendering. `text` placeholders are substituted verbatim (a
     * charge's name is the operator's own words and is never translated); `month` and `date` ones
     * hold an ISO date and are formatted for the reader.
     *
     * A `trans` placeholder holds a CODE and is worded through a lang GROUP at read time; a
     * `catalogue` one goes through the catalogue's own `labelFor()`, which reads INACTIVE rows so
     * retiring a code never blanks a line on a document that carries it. Both exist because of the
     * failure `LeaseEventNarrative` hit on screen: a classification resolved with a bare `trans()`
     * at write time produced ONE SENTENCE IN TWO LANGUAGES — an Arabic label beside English words —
     * which is worse than either language alone.
     *
     * @var array<string, array{lang: string, text?: list<string>, month?: list<string>, date?: list<string>, trans?: array<string, string>, catalogue?: array<string, class-string>}>
     */
    public const KEYS = [
        // ── The recurring line, on every monthly invoice in the portfolio ──────────────────────
        // Four keys for a 2×2, because the whole sentence is the template. `name` is the charge's
        // own name, which the operator typed.
        'billing.period' => ['lang' => 'admin.invoice_lines.period', 'text' => ['name'], 'month' => ['period']],
        'billing.period_arrears' => ['lang' => 'admin.invoice_lines.period_arrears', 'text' => ['name'], 'month' => ['period']],
        'billing.period_prorated' => ['lang' => 'admin.invoice_lines.period_prorated', 'text' => ['name', 'pct'], 'month' => ['period']],
        'billing.period_arrears_prorated' => ['lang' => 'admin.invoice_lines.period_arrears_prorated', 'text' => ['name', 'pct'], 'month' => ['period']],
        // A cycle spanning more than one month states both ends, so `period` is prose the caller
        // built from two dates and there is nothing here to re-format.
        'billing.cycle' => ['lang' => 'admin.invoice_lines.cycle', 'text' => ['name', 'period']],
        'billing.cycle_arrears' => ['lang' => 'admin.invoice_lines.cycle_arrears', 'text' => ['name', 'period']],

        // ── The five that already had a key and resolved it too early ─────────────────────────
        'late_fee.line' => ['lang' => 'admin.actions.late_fee_line_description', 'text' => ['percent', 'balance', 'min', 'invoice']],
        'utility.recharge' => ['lang' => 'admin.utility.recharge_line', 'text' => ['meter', 'consumption', 'uom'], 'month' => ['period'], 'trans' => ['type' => 'admin.enums.meter_type']],
        'nsf_fee.line' => ['lang' => 'admin.post_dated_cheques.nsf_fee_line', 'text' => ['cheque', 'bank']],
        'deposit.line' => ['lang' => 'admin.deposits.invoice_line', 'text' => ['ref']],

        // ── The annual recovery, on the document a tenant queries hardest ─────────────────────
        'cam.reconciliation' => ['lang' => 'admin.invoice_lines.cam_reconciliation', 'text' => ['year']],
        'cam.admin_fee' => ['lang' => 'admin.invoice_lines.cam_admin_fee', 'text' => ['year']],
        'percentage_rent.line' => ['lang' => 'admin.invoice_lines.percentage_rent', 'text' => ['label']],
        'violation.fine' => ['lang' => 'admin.violations.fine_line', 'text' => ['reference'], 'date' => ['date'], 'catalogue' => ['category' => ViolationCategory::class]],
    ];

    /**
     * The line's text, in `$locale` (the current locale by default).
     *
     * @param  array<string, mixed>|null  $data
     */
    public static function resolve(
        ?string $key,
        ?array $data = null,
        ?string $prose = null,
        ?string $locale = null,
    ): string {
        $locale ??= App::getLocale();

        return self::fromKey($key, $data ?? [], $locale) ?? (string) $prose;
    }

    /** The translated line, or null when there is no usable key. */
    private static function fromKey(?string $key, array $data, string $locale): ?string
    {
        if ($key === null || ! array_key_exists($key, self::KEYS)) {
            return null;
        }

        $spec = self::KEYS[$key];

        // `fallback: false` — `Lang::has()` falls back to English by default, so without this an
        // Arabic reader silently gets English for a key nobody translated and the parity gate never
        // sees it. The trap `ActivityVocabulary` and `JournalNarrative` both document.
        if (! Lang::has($spec['lang'], $locale, fallback: false)) {
            return null;
        }

        $replace = [];

        foreach ($spec['text'] ?? [] as $placeholder) {
            $replace[$placeholder] = self::verbatim($data[$placeholder] ?? null);
        }

        foreach ($spec['month'] ?? [] as $placeholder) {
            $replace[$placeholder] = self::month($data[$placeholder] ?? null, $locale);
        }

        foreach ($spec['date'] ?? [] as $placeholder) {
            $replace[$placeholder] = self::date($data[$placeholder] ?? null, $locale);
        }

        foreach ($spec['trans'] ?? [] as $placeholder => $group) {
            $code = $data[$placeholder] ?? null;
            $words = $code === null ? null : (trans($group, [], $locale)[$code] ?? null);
            $replace[$placeholder] = self::verbatim($words ?? $code);
        }

        foreach ($spec['catalogue'] ?? [] as $placeholder => $model) {
            $code = $data[$placeholder] ?? null;
            // `labelFor()` answers in the CURRENT locale, and every reader of a document runs
            // inside `DocumentLocale::in()`, so switch for the duration rather than threading a
            // locale through a catalogue API that has none.
            $words = $code === null ? null : self::inLocale(
                $locale,
                fn (): string => $model::labelFor((string) $code),
            );
            $replace[$placeholder] = self::verbatim($words ?? $code);
        }

        return trans($spec['lang'], $replace, $locale);
    }

    /**
     * A missing value renders an em dash, never a leftover `:placeholder`.
     *
     * On a tax invoice a raw `:balance` reads as a broken template rather than an absent figure,
     * which is the same call {@see JournalNarrative} makes for a financial statement.
     */
    private static function verbatim(mixed $value): string
    {
        return ($value === null || $value === '') ? '—' : (string) $value;
    }

    /** «سبتمبر ٢٠٢٦» / "September 2026" from one stored ISO date. */
    private static function month(mixed $value, string $locale): string
    {
        return self::formatted($value, $locale, 'MMMM YYYY');
    }

    /** A short date in the reader's own calendar conventions. */
    private static function date(mixed $value, string $locale): string
    {
        return self::formatted($value, $locale, 'll');
    }

    /** Run a callback with `$locale` active, restoring the previous one even if it throws. */
    private static function inLocale(string $locale, callable $callback): mixed
    {
        $previous = App::getLocale();

        if ($previous === $locale) {
            return $callback();
        }

        App::setLocale($locale);

        try {
            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    private static function formatted(mixed $value, string $locale, string $format): string
    {
        if ($value === null || $value === '') {
            return '—';
        }

        $date = $value instanceof CarbonInterface ? $value : rescue(
            fn (): Carbon => Carbon::parse((string) $value),
            null,
            report: false,
        );

        // A value that is not a date at all renders verbatim rather than as an em dash: it is more
        // likely a period somebody typed than a corrupt row, and losing it says less than showing it.
        return $date?->locale($locale)->isoFormat($format) ?? (string) $value;
    }
}
