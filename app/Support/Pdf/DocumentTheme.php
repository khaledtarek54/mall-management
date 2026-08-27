<?php

namespace App\Support\Pdf;

/**
 * **The palette every issued document is set in.**
 *
 * Six templates each carried their own copy of these hex values inline — the same teal spelled in
 * six places, the same near-black in six more — which is why the credit note's rules were a shade
 * lighter than the invoice's and the receipt's muted grey was a different grey again. Nobody chose
 * any of that; it is what happens when a colour is a literal in markup.
 *
 * The values are chosen for PRINT, not for a screen. Three properties are load-bearing and each has
 * been checked rather than assumed:
 *
 *   - **Everything survives greyscale.** These documents are printed on office mono lasers and
 *     faxed to accountants. {@see ACCENT} and {@see INK} differ in lightness, not only in hue, so a
 *     heading does not dissolve into the body when the colour goes.
 *   - **The tints are warm, the ink is cool.** {@see PANEL} is a paper tint rather than a grey box,
 *     which is what stops a tinted band reading as a screenshot artefact once it is on paper.
 *   - **{@see DUE} is used for one thing only** — a figure the reader owes. A document that colours
 *     four things red has told the reader nothing about which one is urgent.
 *
 * There is deliberately NO per-property accent here, though {@see \App\Support\Filament\PanelBranding}
 * could supply one: an operator-typed `primary_color` has no contrast guarantee, and a tax invoice
 * whose total is set in a colour nobody checked against its background is a worse document than a
 * uniform one. The mall's identity reaches these pages through its logo, which is already per
 * property.
 */
final class DocumentTheme
{
    /** Headings, figures, anything the eye should land on first. */
    public const INK = '#12161A';

    /** Body copy — softened from the headline ink so a dense table does not read as one block. */
    public const BODY = '#3D434A';

    /** Field labels, captions, the running footer. Never a figure. */
    public const MUTED = '#8B8578';

    /** Hairlines between rows. Light enough to structure a table without ruling it. */
    public const RULE = '#E3DED3';

    /** A heavier rule, for the line that closes a section. */
    public const RULE_STRONG = '#C9C2B4';

    /** The warm paper tint behind a facts strip or a note. */
    public const PANEL = '#F7F4EC';

    /** The brand accent: document titles, the rule under the masthead, the balance rule. */
    public const ACCENT = '#0F766E';

    /** The accent at panel strength, for a block that belongs to the accent rather than the paper. */
    public const ACCENT_TINT = '#E9F2F0';

    /** Reversed-out text on {@see INK} or {@see ACCENT}. Not white — warm, to match the paper. */
    public const REVERSED = '#F7F4EC';

    /** A figure the reader owes. Reserved for exactly that. */
    public const DUE = '#A8452A';

    /** Settled, cleared, in credit. */
    public const SETTLED = '#2D6B3F';

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
            'paid', 'cleared', 'approved', 'applied', 'completed' => ['#E4F0E7', self::SETTLED],
            'overdue', 'bounced', 'rejected' => ['#F7E2DC', '#93301B'],
            'partially_paid', 'pending', 'submitted', 'draft' => ['#FBF0D9', '#8A6212'],
            'cancelled', 'written_off', 'void', 'voided' => ['#EDEAE3', '#6E6759'],
            default => ['#E6EEF6', '#1C4C87'],
        };
    }
}
