<?php

namespace App\Support\Pdf;

use App\Support\Filament\PanelBranding;

/**
 * **The palette every issued document is set in.**
 *
 * Six templates each carried their own copy of these hex values inline — the same accent spelled in
 * six places, the same near-black in six more — which is why the credit note's rules were a shade
 * lighter than the invoice's and the receipt's muted grey was a different grey again. Nobody chose
 * any of that; it is what happens when a colour is a literal in markup.
 *
 * ## Direction D, chosen 2026-08-28
 *
 * Four directions were drawn as A4 invoices and put side by side, in both languages, before any of
 * this was written — a restrained ruled ledger, a dense administrative grid, a formal letterhead,
 * and this one. **The operator picked the brand-forward one**: a full-bleed navy band carrying the
 * mall's identity, and the balance set apart in an amber panel of its own.
 *
 * The tradeoff was stated when it was picked and is worth keeping in view: this is the heaviest of
 * the four on ink and the weakest in greyscale. {@see DUE} and {@see ACCENT} are therefore separated
 * in LIGHTNESS as well as hue, so a photocopied invoice still distinguishes the balance panel from
 * the paper around it.
 *
 * Three properties are load-bearing and each has been checked rather than assumed:
 *
 *   - **The band is the only large ink field.** Everything below it is white paper with hairlines,
 *     which is what keeps a brand-forward document from drinking a cartridge per statement.
 *   - **{@see ACCENT} is spent on ONE thing per page** — the figure the reader owes. A document that
 *     colours four things has told the reader nothing about which one is urgent.
 *   - **{@see BAND_MUTED} is legible on {@see INK} and nowhere else.** It is the only colour here
 *     with a single permitted background, and using it on paper produces unreadable grey-on-white.
 *
 * There is deliberately NO per-property accent, though {@see PanelBranding}
 * could supply one: an operator-typed `primary_color` has no contrast guarantee, and this direction
 * reverses text out of that colour — a mall that picks a pale brand colour would issue invoices with
 * a white-on-yellow masthead. The mall's identity reaches the page through its name and logo in the
 * band, which are already per property.
 */
final class DocumentTheme
{
    /** The band, and the ink everything important is set in. */
    public const INK = '#14213D';

    /** Body copy — softened from the headline ink so a dense table does not read as one block. */
    public const BODY = '#4A5468';

    /** Field labels, captions, the running footer. Never a figure. */
    public const MUTED = '#7D8595';

    /** Hairlines between rows. Light enough to structure a table without ruling it. */
    public const RULE = '#EBEEF3';

    /** A heavier rule, for the line that closes a section. */
    public const RULE_STRONG = '#C9D0DC';

    /** The cool tint behind a table heading, a facts strip or a note. */
    public const PANEL = '#F2F4F8';

    /** The accent. Reserved for the figure the reader owes, and for the band's document type. */
    public const ACCENT = '#E8A33D';

    /** The accent at panel strength — the ground the balance sits on. */
    public const ACCENT_TINT = '#FBF4E7';

    /** Reversed-out text on {@see INK}. */
    public const REVERSED = '#FFFFFF';

    /**
     * Secondary text ON THE BAND, and only there.
     *
     * The one colour in this palette with a single permitted background: it is tuned to sit under
     * {@see REVERSED} on {@see INK}, and on white paper it is unreadable.
     */
    public const BAND_MUTED = '#A8B2C8';

    /** A figure the reader owes. Reserved for exactly that. */
    public const DUE = '#B4462C';

    /** Settled, cleared, in credit. */
    public const SETTLED = '#2E6B4F';

    /**
     * The tint behind a status chip, by document state.
     *
     * Keyed by the states the money documents actually carry. An unknown state falls back to the
     * neutral pair rather than throwing — a document must still print when a new status ships
     * before this map hears about it, and a grey chip reading the state's own name is honest.
     *
     * @return array{0: string, 1: string} [background, text]
     */
    public static function chip(string $status): array
    {
        return match ($status) {
            'paid', 'cleared', 'approved', 'applied', 'completed' => ['#DCEDE4', self::SETTLED],
            'overdue', 'bounced', 'rejected' => ['#F7DFD8', '#93301B'],
            'partially_paid', 'pending', 'submitted', 'draft' => [self::ACCENT, self::INK],
            'cancelled', 'written_off', 'void', 'voided' => ['#E4E7EE', '#5C6478'],
            default => ['#DDE4F0', self::INK],
        };
    }

    /**
     * The chip as it appears ON THE BAND, where the paper tints have nothing to sit against.
     *
     * Reversed out rather than tinted: a pale chip on navy disappears, and the states that reach a
     * masthead are the ones a reader must not miss.
     *
     * @return array{0: string, 1: string} [background, text]
     */
    public static function bandChip(string $status): array
    {
        return match ($status) {
            'paid', 'cleared', 'approved', 'applied', 'completed' => ['#7FC49E', self::INK],
            'overdue', 'bounced', 'rejected' => ['#E07A63', self::INK],
            'cancelled', 'written_off', 'void', 'voided' => ['#5A6480', self::REVERSED],
            default => [self::ACCENT, self::INK],
        };
    }
}
