<?php

namespace App\Support\Search;

/**
 * The one place a searchable string is folded into its comparable form.
 *
 * Why this exists: an Egyptian operator types the same name three ways. «أحمد»
 * and «احمد» differ by a hamza; «شركة» and «شركه» by a teh marbuta; «مصطفى» and
 * «مصطفي» by an alef maqsura. Those are the SAME word to a human and different
 * strings to `LIKE`. Before this class, searching a tenant by the spelling you
 * had in your head and the spelling the leasing officer typed was a coin flip,
 * and the failure was silent — an empty result set looks identical to "no such
 * tenant".
 *
 * The same fold buys punctuation-insensitivity on document numbers for free:
 * `INV-2026-0042` and `INV20260042` both reduce to `inv20260042`, so an operator
 * reading a number off a printed invoice finds it whether or not they type the
 * dashes.
 *
 * Both sides must be folded with THIS class — the stored `search_text` column
 * (via `HasSearchText`) and the operator's query (via `SearchesNormalizedText` /
 * the table search callbacks). Folding only one side matches nothing, which is
 * why neither side normalizes inline anywhere else in the codebase.
 *
 * Deliberately NOT done here:
 * - Stemming / root extraction. Arabic roots are a linguistics project, and an
 *   ERP searches proper nouns and document numbers, not prose.
 * - Space substitution for punctuation. Stripping `-` rather than replacing it
 *   with a space is what makes `INV2026` match `INV-2026`; a space would break
 *   that, and multi-word matching already works because terms are split on
 *   whitespace and ANDed (see `words()`).
 */
class SearchText
{
    /**
     * Arabic letter/mark folds, applied before the punctuation strip.
     *
     * Key order matters only in that every key is a distinct codepoint, so a
     * single strtr pass is unambiguous.
     */
    private const ARABIC_FOLD = [
        // ---- Tashkeel (diacritics). Optional in writing; almost never typed
        //      into a form, but pasted text from Word documents carries them.
        "\u{064B}" => '', // fathatan
        "\u{064C}" => '', // dammatan
        "\u{064D}" => '', // kasratan
        "\u{064E}" => '', // fatha
        "\u{064F}" => '', // damma
        "\u{0650}" => '', // kasra
        "\u{0651}" => '', // shadda
        "\u{0652}" => '', // sukun
        "\u{0653}" => '', // maddah above
        "\u{0654}" => '', // hamza above
        "\u{0655}" => '', // hamza below
        "\u{0656}" => '', // subscript alef
        "\u{0657}" => '', // inverted damma
        "\u{0658}" => '', // mark noon ghunna
        "\u{0670}" => '', // superscript alef

        // ---- Tatweel: a pure typographic stretch, never semantic.
        "\u{0640}" => '',

        // ---- Alef family → bare alef. The single most common Arabic
        //      spelling variance in names (أحمد / احمد / إبراهيم / ابراهيم).
        "\u{0622}" => "\u{0627}", // آ alef with madda
        "\u{0623}" => "\u{0627}", // أ alef with hamza above
        "\u{0625}" => "\u{0627}", // إ alef with hamza below
        "\u{0671}" => "\u{0627}", // ٱ alef wasla

        // ---- Teh marbuta → heh (شركة / شركه — endemic in company names).
        "\u{0629}" => "\u{0647}",

        // ---- Alef maqsura → yeh (مصطفى / مصطفي).
        "\u{0649}" => "\u{064A}",

        // ---- Hamza carriers → their base letter (مؤسسة / موسسة, رئيس / رييس).
        "\u{0624}" => "\u{0648}", // ؤ
        "\u{0626}" => "\u{064A}", // ئ
        "\u{0621}" => '',         // ء standalone hamza

        // ---- Arabic-Indic digits → ASCII. An invoice number shown as
        //      ٢٠٢٦ must be findable by typing 2026 and vice versa.
        "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
        "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',

        // ---- Extended Arabic-Indic (Persian/Urdu keyboards reach Egyptian
        //      desks often enough to be worth the eight lines).
        "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
        "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
    ];

    /**
     * Fold one string into its comparable form.
     *
     * Returns '' for null/blank so callers can concatenate without null checks.
     */
    public static function normalize(?string $value): string
    {
        if ($value === null || trim($value) === '') {
            return '';
        }

        // Canonical decomposition first, so a pre-composed «أ» (U+0623) and a
        // decomposed alef+hamza (U+0627 U+0654) fold to the same thing. Both
        // occur in the wild — the pre-composed form from Windows keyboards,
        // the decomposed form from some macOS/iOS input paths and from text
        // round-tripped through certain PDF extractors. Without this, the two
        // spellings of the SAME name would still not match after folding.
        //
        // Guarded: ext-intl is present on this machine but is not declared in
        // composer.json, so a deploy target without it must degrade to
        // "pre-composed only" rather than fatal.
        if (class_exists(\Normalizer::class)) {
            $value = \Normalizer::normalize($value, \Normalizer::FORM_D) ?: $value;
        }

        $value = mb_strtolower($value, 'UTF-8');

        $value = strtr($value, self::ARABIC_FOLD);

        // Strip combining marks left over from the FORM_D decomposition above
        // (Latin accents: café → cafe) plus any Arabic mark the fold missed.
        $value = preg_replace('/\p{Mn}+/u', '', $value) ?? $value;

        // Everything that is not a letter, a digit or whitespace disappears
        // WITHOUT leaving a space — see the class docblock for why.
        $value = preg_replace('/[^\p{L}\p{N}\s]+/u', '', $value) ?? $value;

        // Collapse runs of any whitespace (including the Arabic tatweel-ish
        // zero-width joiners Word likes to insert) to a single space.
        $value = preg_replace('/[\s\x{200B}-\x{200F}\x{FEFF}]+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    /**
     * Fold a query and split it into the words to AND together.
     *
     * Filament's own global search splits on whitespace and requires every word
     * to match; we keep that semantic so "zara cairo" narrows rather than widens.
     * Words are deduplicated (typing the same word twice should not cost a
     * second LIKE) and empty results are dropped, so a query of pure punctuation
     * yields `[]` — which callers MUST read as "do not search", not "match all".
     *
     * @return list<string>
     */
    public static function words(?string $query): array
    {
        $normalized = self::normalize($query);

        if ($normalized === '') {
            return [];
        }

        return array_values(array_unique(array_filter(
            explode(' ', $normalized),
            fn (string $word): bool => $word !== '',
        )));
    }

    /**
     * Build the stored blob from a model's own values.
     *
     * Each value is folded independently and joined with a space, so a word can
     * never be formed by two adjacent fields running together (which would let
     * "zaraahmed" match a tenant named Zara whose contact is Ahmed).
     * Duplicates are dropped — a tenant whose `name` and `legal_name` are the
     * same string stores it once.
     *
     * @param  array<int, string|int|float|null>  $values
     */
    public static function blob(array $values): string
    {
        $parts = [];

        foreach ($values as $value) {
            if ($value === null) {
                continue;
            }

            $normalized = self::normalize((string) $value);

            if ($normalized !== '') {
                $parts[] = $normalized;
            }
        }

        return implode(' ', array_values(array_unique($parts)));
    }
}
