<?php

namespace App\Support\Pdf;

/**
 * **Operator-typed text keeps its own direction inside a document written in the other one.**
 *
 * An Arabic invoice is not all Arabic. The tenant's registered name is often Latin, the item
 * descriptions are whatever the billing run wrote, the phone numbers start with `+` and the
 * references are codes — and the Unicode bidi algorithm resolves a NEUTRAL character (a full stop, a
 * plus, a slash, a dash) by the direction of what surrounds it. Inside an RTL paragraph that puts
 * the neutral at the wrong end:
 *
 *     stored:     Issued in error; voided same day.        rendered:  .Issued in error; voided same day
 *     stored:     +201808046413                            rendered:  201808046413+
 *
 * Both were on the shipped Arabic documents, on the credit-note reason and the party block. Neither
 * is a font problem or a template problem — the text is correct and the layout is correct, and the
 * result still reads as a typo the operator did not make. Worse, it is INTERMITTENT: whether the
 * period moves depends on what else sits in the same line box, so the same field renders correctly
 * on one document and wrongly on the next, which is why it survived review.
 *
 * ## Why marks and not isolates
 *
 * The modern answer is U+2068 FIRST STRONG ISOLATE … U+2069 POP DIRECTIONAL ISOLATE, or HTML's
 * `<bdi>`. **mpdf implements neither** — measured, both leave the run reordered and the isolate
 * characters swallowed, which is worse than doing nothing because it looks handled. What mpdf does
 * honour is the older U+200E LEFT-TO-RIGHT MARK / U+200F RIGHT-TO-LEFT MARK, so this reproduces an
 * isolate the way it was done before isolates existed: decide the run's direction from its own first
 * strong character, then fence it with the matching mark at both ends.
 *
 * The marks are zero-width and carry no glyph, so they cost nothing on the page. They do travel into
 * the PDF's text layer, which is the accepted price — copying a phone number out of the document
 * yields the number with an invisible mark at each end.
 *
 * ## What to wrap
 *
 * Anything a PERSON typed or an importer carried in, wherever it is rendered next to text in the
 * document's own language: party names and addresses, line descriptions, notes, references, phone
 * numbers, e-mail addresses. NOT the document's own translated labels — those are already in the
 * document's direction by construction, and fencing them would only add noise.
 */
final class Bidi
{
    /** U+200E — makes the run that follows read left-to-right. */
    public const LRM = "\u{200E}";

    /** U+200F — makes the run that follows read right-to-left. */
    public const RLM = "\u{200F}";

    /**
     * Fence a value so it renders in its OWN direction, whatever surrounds it.
     *
     * Empty and whitespace-only values come back untouched: a pair of marks around nothing is two
     * invisible characters where a template is testing for emptiness, and `@if($x)` on a string that
     * is now two marks long is true.
     */
    public static function isolate(?string $value): string
    {
        $value = (string) $value;

        if (trim($value) === '') {
            return $value;
        }

        $mark = self::isRtlText($value) ? self::RLM : self::LRM;

        return $mark.$value.$mark;
    }

    /**
     * Fence each LINE of a multi-line block separately.
     *
     * For the operator's own prose — payment instructions, terms, the invoice footer — which arrives
     * as a textarea's contents and is rendered through `nl2br`. One pair of marks around the whole
     * block sets the direction of the first line only; a block whose first line is Arabic and whose
     * second is an IBAN would then have the IBAN resolved as RTL. Per line, each is judged on its
     * own first strong character, which is what a reader expects of a block where every line is a
     * separate statement.
     *
     * Blank lines pass through untouched so the block keeps its spacing.
     */
    public static function isolateLines(?string $value): string
    {
        $value = (string) $value;

        if (trim($value) === '') {
            return $value;
        }

        // Normalise CRLF first: a Windows-typed setting would otherwise leave a stray \r inside the
        // fenced run, where it renders as nothing but counts as content.
        $lines = preg_split('/\R/u', str_replace("\r\n", "\n", $value)) ?: [];

        return implode("\n", array_map(self::isolate(...), $lines));
    }

    /**
     * Does this text START right-to-left?
     *
     * FIRST strong character, not "does it contain any Arabic" — that is the whole rule, and it is
     * what makes an Arabic trade name with a Latin suffix («الغرير للتجارة LLC») read as Arabic while
     * an English name with an Arabic gloss reads as English. Digits and punctuation are skipped
     * because they are not strong: a reference beginning `2026/` takes its direction from whatever
     * letter comes first, and from LTR if there is none, which is right for a code.
     *
     * The ranges are the RTL blocks a document in this system can realistically carry: Arabic and
     * its supplement/extended blocks, Arabic Presentation Forms, and Hebrew. Anything else is
     * treated as left-to-right, which is the safe direction to be wrong in — a Latin run fenced LTR
     * inside an Arabic line still reads correctly.
     */
    public static function isRtlText(string $value): bool
    {
        // \p{L} alone would match the first LETTER; the two explicit classes let a single pass find
        // whichever kind of strong character comes first without scanning twice.
        if (! preg_match('/[\p{Arabic}\p{Hebrew}]|\p{L}/u', $value, $m)) {
            return false;
        }

        return (bool) preg_match('/^[\p{Arabic}\p{Hebrew}]$/u', $m[0]);
    }
}
