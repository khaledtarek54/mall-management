<?php

namespace App\Support;

use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/**
 * What a journal entry SAYS, resolved when it is read (EG-36, finding S-12).
 *
 * The ledger's twin of {@see ActivityVocabulary}, and for the same reason CLAUDE.md gives there:
 * **a row stores DATA, never PROSE.** Twenty-four journalizers wrote Arabic and English literals
 * into `description_ar` / `description_en` at post time, so a wording fix needed a deploy, never
 * reached a row already posted, and a third language would have meant re-posting history.
 *
 * A narrative is now a KEY plus its placeholders: `invoice.posted` with `{"number": "INV-0001"}`.
 * Both languages come from `lang/{en,ar}/admin/accounting.php` at read time, so one edit reaches
 * every entry ever posted under that key.
 *
 * ## The stored prose is the FLOOR, not the truth
 *
 * Every row posted before this existed has prose and no key, and a manual entry is prose the
 * operator typed — both must read correctly for ever. So the key wins where there is one and the
 * prose answers otherwise, which also means a read site nobody converted degrades to today's
 * wording rather than to a blank cell. On a general ledger an empty description is
 * indistinguishable from an entry nobody described.
 *
 * ## Why a registry rather than a free key
 *
 * A narrative written for a key with no translation renders the raw key on a financial statement —
 * `invoice.postd` on a document an auditor reads. {@see KEYS} names every narrative and the
 * placeholders it takes, and `JournalNarrativeConformanceTest` requires each to resolve in BOTH
 * languages with no leftover `:placeholder`.
 */
final class JournalNarrative
{
    /**
     * Every narrative a journalizer may post, and the placeholders it takes.
     *
     * The keys are `<source>.<what happened>`, matching the shape `ActivityVocabulary` uses for its
     * descriptions. A journalizer that needs a new one adds it here first — the conformance test
     * fails on a key with no translation, and on a translation with a placeholder nobody supplies.
     *
     * @var array<string, list<string>>
     */
    public const KEYS = [
        'credit_note.posted' => ['number'],
        'custody.posted' => ['name'],
        'custody.returned' => [],
        'custody.spent' => ['category'],
        'deposit.applied' => ['reference'],
        'deposit.movement' => ['type', 'number'],
        'depreciation.posted' => ['asset'],
        'disbursement.posted' => ['reference'],
        'employee_advance.granted' => ['name'],
        'employee_advance.repaid' => [],
        'expense.posted' => ['number'],
        'fixed_asset.acquired' => ['asset'],
        'fixed_asset.disposed' => ['asset'],
        'invoice.posted' => ['number'],
        'invoice.written_off' => ['number'],
        'marketing_spend.posted' => ['category'],
        'owner_statement.posted' => ['reference'],
        'payment.posted' => ['reference'],
        'payroll.posted' => ['number'],
        // The reversal family. `<source>.<what happened>` everywhere else; here the "source" is the
        // posting engine itself, which is the one writer of an entry nothing journalizes.
        'reversal.no_effect' => ['number'],
        'reversal.posted' => ['number'],
        'reversal.reason' => ['number', 'reason'],
        'reversal.superseded' => ['number'],
        'reversal.year_reopened' => ['number', 'year'],
        'sla_penalty.applied' => ['reference', 'bill'],
        'stock_movement.posted' => ['type', 'item'],
        'straight_line_rent.posted' => ['period'],
        'tenant_credit.applied' => ['reference'],
        'vendor_bill.posted' => ['number'],
        'vendor_bill.paid' => ['reference', 'bill'],
    ];

    /** Where a narrative's wording lives. One group, so a translator sees them together. */
    public const LANG_PREFIX = 'admin.journal.narratives.';

    /**
     * The narrative for an entry, in `$locale` (the current locale by default).
     *
     * @param  array<string, string|int|float|null>|null  $data
     */
    public static function resolve(
        ?string $key,
        ?array $data = null,
        ?string $en = null,
        ?string $ar = null,
        ?string $locale = null,
    ): string {
        $locale = $locale ?? App::getLocale();

        $fromKey = self::fromKey($key, $data ?? [], $locale);

        if ($fromKey !== null) {
            return $fromKey;
        }

        // The floor: what the row has said since it was posted. Prefer the reader's language and
        // fall back to the other rather than showing nothing — a ledger line with no description
        // reads as an entry nobody described.
        $own = $locale === 'ar' ? (string) $ar : (string) $en;
        $other = $locale === 'ar' ? (string) $en : (string) $ar;

        return $own !== '' ? $own : $other;
    }

    /** The translated narrative, or null when there is no usable key. */
    private static function fromKey(?string $key, array $data, string $locale): ?string
    {
        if ($key === null || ! array_key_exists($key, self::KEYS)) {
            return null;
        }

        $langKey = self::LANG_PREFIX.$key;

        // `fallback: false` — `Lang::has()` falls back to English by default, so without this an
        // Arabic reader would silently get English for a key nobody translated and the parity gate
        // would never see it. Same trap `ActivityVocabulary` documents.
        if (! Lang::has($langKey, $locale, fallback: false)) {
            return null;
        }

        // Missing placeholders are replaced with an em dash rather than left as `:number`, which on
        // a financial statement reads as a broken template rather than as an absent reference.
        $replace = [];

        foreach (self::KEYS[$key] as $placeholder) {
            $value = $data[$placeholder] ?? null;
            $replace[$placeholder] = ($value === null || $value === '') ? '—' : (string) $value;
        }

        return trans($langKey, $replace, $locale);
    }
}
