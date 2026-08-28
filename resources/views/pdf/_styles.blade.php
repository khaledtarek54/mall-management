{{--
    The stylesheet EVERY issued document is set in.

    Six templates carried ~150 near-identical lines of this each, and they had already drifted: two
    used a 2px accent rule where four used 3px, the muted grey was spelled three ways, and the
    receipt's table cells were 2px tighter than the invoice's for no reason anyone could name. One
    stylesheet is the point — a change to how these documents are typeset should be one edit.

    NO `@page` rule here, deliberately. Page geometry (size, margins, the space the running footer
    sits in) belongs to `App\Support\Pdf\PdfDocument`, which is the thing that also knows there IS a
    footer. Every template used to set its own `@page { margin: … }` in px, which silently overrode
    mpdf's own margins and left no room beneath the body — so the running footer these documents now
    carry rendered nowhere at all until the rule was removed.

    RTL: Arabic is cursive. `letter-spacing` pulls the joins apart and `text-transform: uppercase`
    means nothing on it, so both are guarded on $isRtl throughout — enforced by
    `PdfDocumentConformanceTest`, which follows @include for exactly this file.
--}}
@php
    use App\Support\Pdf\DocumentTheme as T;

    $start = $isRtl ? 'right' : 'left';
    $end = $isRtl ? 'left' : 'right';
    // Tracking and casing are Latin typography. On Arabic both are wrong, so the whole idiom
    // collapses to "no tracking, no casing" rather than being re-tuned per rule.
    $track = $isRtl ? '0' : '0.09em';
    $caps = $isRtl ? 'none' : 'uppercase';
@endphp
<style>
    * { box-sizing: border-box; }

    body {
        font-family: {{ \App\Support\Pdf\PdfDocument::FONT }};
        color: {{ T::BODY }};
        font-size: 9.5pt;
        line-height: 1.5;
        margin: 0;
    }

    /* ── Rhythm ──────────────────────────────────────────────────────────────────────────────
       One spacing scale. A document whose gaps are 14px, 18px, 20px and 24px reads as unresolved
       even to someone who cannot say why; these are the only four gaps used. */
    .gap-s  { margin-bottom: 6pt; }
    .gap-m  { margin-bottom: 12pt; }
    .gap-l  { margin-bottom: 18pt; }
    .gap-xl { margin-bottom: 26pt; }

    /* ── Masthead ────────────────────────────────────────────────────────────────────────── */
    .masthead { width: 100%; border-collapse: collapse; }
    .masthead td { vertical-align: top; padding: 0; }
    .masthead .issuer { width: 58%; }
    .masthead .document { width: 42%; text-align: {{ $end }}; }

    .issuer-name {
        font-size: 15pt;
        font-weight: bold;
        color: {{ T::INK }};
        line-height: 1.25;
    }
    .issuer-line { color: {{ T::MUTED }}; font-size: 8.5pt; line-height: 1.45; }
    .issuer-line strong { color: {{ T::BODY }}; font-weight: bold; }

    .doc-type {
        {{-- Arabic sets smaller than Latin at the same point size — the x-height is lower and there
             are no caps — so an identical value makes the Arabic title look like a subheading beside
             the English one. Two sizes, one intended weight on the page. --}}
        font-size: {{ $isRtl ? '18pt' : '16pt' }};
        font-weight: bold;
        color: {{ T::ACCENT }};
        letter-spacing: {{ $isRtl ? '0' : '0.06em' }};
        text-transform: {{ $caps }};
        line-height: 1.2;
    }
    .doc-number {
        font-size: 11pt;
        font-weight: bold;
        color: {{ T::INK }};
        margin-top: 1pt;
    }

    /* The rule that separates who issued this from what it says. */
    .masthead-rule {
        border-bottom: 1.6pt solid {{ T::ACCENT }};
        margin-top: 10pt;
        margin-bottom: 16pt;
        font-size: 0;
        line-height: 0;
    }

    /* ── Labels ──────────────────────────────────────────────────────────────────────────────
       The one caption style. Small, tracked, muted — it names a field and then gets out of the way. */
    .label {
        font-size: 7pt;
        color: {{ T::MUTED }};
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        font-weight: bold;
        line-height: 1.4;
    }

    /* ── Facts strip ─────────────────────────────────────────────────────────────────────────
       The three-column band under the masthead: who is billed, against what agreement, on what
       dates. Replaces two 50% blocks plus a separate full-width period bar — same information,
       one band, and the dates stop being an afterthought at the bottom. */
    .facts { width: 100%; border-collapse: collapse; }
    .facts > tbody > tr > td {
        vertical-align: top;
        padding: 0;
        padding-{{ $end }}: 14pt;
    }
    .facts > tbody > tr > td.last { padding-{{ $end }}: 0; }
    .facts .value { color: {{ T::BODY }}; font-size: 9pt; line-height: 1.5; }
    .facts .headline {
        color: {{ T::INK }};
        font-size: 10.5pt;
        font-weight: bold;
        line-height: 1.35;
        margin-top: 3pt;
        margin-bottom: 1pt;
    }
    /* A definition row inside a facts column: caption left, value right, so several of them
       line up as a column rather than reading as a paragraph. */
    .facts .pair { width: 100%; border-collapse: collapse; }
    .facts .pair td { padding: 0 0 2pt 0; font-size: 9pt; }
    .facts .pair td.k {
        color: {{ T::MUTED }};
        text-align: {{ $start }};
        white-space: nowrap;
        /* Both cells are nowrap, so without a gutter a long value butts straight against its
           caption — "Billing Period01/08/2026 – 31/08/2026", which is what shipped. */
        padding-{{ $end }}: 10pt;
    }
    .facts .pair td.v { color: {{ T::BODY }}; text-align: {{ $end }}; white-space: nowrap; }

    /* ── Status chip ─────────────────────────────────────────────────────────────────────── */
    .chip {
        display: inline-block;
        padding: 3pt 8pt;
        border-radius: 2pt;
        font-size: 7.5pt;
        font-weight: bold;
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
    }

    /* ── Line items ──────────────────────────────────────────────────────────────────────────
       A dark header band, hairlines between rows, nothing else. The band survives greyscale, marks
       where the figures start, and REPEATS on every page — mpdf carries <thead> over a break, which
       is the whole reason the header is a real thead and not a styled first row. */
    table.items { width: 100%; border-collapse: collapse; }
    table.items thead th {
        background: {{ T::INK }};
        color: {{ T::REVERSED }};
        text-align: {{ $start }};
        padding: 7pt 9pt;
        font-size: 8pt;
        font-weight: bold;
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        white-space: nowrap;
    }
    table.items thead th.num { text-align: {{ $end }}; }
    table.items tbody td {
        padding: 7.5pt 9pt;
        border-bottom: 0.4pt solid {{ T::RULE }};
        vertical-align: top;
        color: {{ T::BODY }};
        word-wrap: break-word;
    }
    table.items tbody td.num { text-align: {{ $end }}; white-space: nowrap; }
    table.items tbody td.ink { color: {{ T::INK }}; }
    table.items tbody td.muted { color: {{ T::MUTED }}; font-size: 8.5pt; }
    table.items tbody tr.total td {
        border-bottom: none;
        border-top: 0.8pt solid {{ T::RULE_STRONG }};
        font-weight: bold;
        color: {{ T::INK }};
    }
    /* The second line under an item — what it covers, which period, which charge code. */
    .item-note { color: {{ T::MUTED }}; font-size: 8.5pt; margin-top: 1.5pt; }

    /* A SUPPORTING table — a VAT split, a breakdown that explains the table above rather than
       standing beside it. Two solid dark bands stacked ten points apart read as two documents;
       a tinted band under a rule reads as what it is, a note on the figures. */
    table.items.secondary thead th {
        background: {{ T::PANEL }};
        color: {{ T::INK }};
        border-bottom: 0.8pt solid {{ T::RULE_STRONG }};
    }

    /* ── Totals ──────────────────────────────────────────────────────────────────────────────
       Right-aligned against the items table, not full width: a total that spans the page is a
       banner, and the reader's eye should travel DOWN the figures column it already found. */
    .totals-wrap { width: 100%; border-collapse: collapse; }
    .totals-wrap > tbody > tr > td { padding: 0; vertical-align: top; }
    .totals-wrap .spacer { width: 52%; }

    table.totals { width: 100%; border-collapse: collapse; }
    table.totals td { padding: 4.5pt 9pt; font-size: 9.5pt; }
    table.totals td.k { color: {{ T::MUTED }}; text-align: {{ $start }}; }
    table.totals td.v { color: {{ T::BODY }}; text-align: {{ $end }}; white-space: nowrap; }
    table.totals tr.rule td { border-top: 0.4pt solid {{ T::RULE }}; }

    /* Every emphasised row states the colour on BOTH cells rather than on the row.
       mpdf's cascade is an approximation of the real one, and a `tr.grand td` rule did NOT beat the
       `td.v` rule it outranks in a browser — so the grand total's FIGURE rendered in body grey on a
       near-black band, which is the least legible thing on the page and the one number the reader
       came for. Explicit beats clever here. */
    table.totals tr.grand td.k,
    table.totals tr.grand td.v {
        background: {{ T::INK }};
        color: {{ T::REVERSED }};
        font-weight: bold;
        font-size: 11.5pt;
        padding: 8pt 9pt;
    }
    table.totals tr.due td.k,
    table.totals tr.due td.v {
        color: {{ T::DUE }};
        font-weight: bold;
        border-top: 0.8pt solid {{ T::RULE_STRONG }};
    }
    table.totals tr.due td.v { font-size: 11.5pt; }
    table.totals tr.settled td.k,
    table.totals tr.settled td.v {
        color: {{ T::SETTLED }};
        font-weight: bold;
        border-top: 0.8pt solid {{ T::RULE_STRONG }};
    }
    table.totals tr.settled td.v { font-size: 11.5pt; }

    /* ── Statement furniture ─────────────────────────────────────────────────────────────────
       A statement is several LISTINGS under headings, where an invoice is one. These are the
       pieces that only those documents need — shared because four of them need the same ones
       (tenant statement, property statement, CAM reconciliation, owner statement) and each had
       its own copy. */
    .section-title {
        font-size: 10pt;
        font-weight: bold;
        color: {{ T::INK }};
        margin-top: 20pt;
        margin-bottom: 6pt;
        padding-bottom: 3pt;
        border-bottom: 0.8pt solid {{ T::RULE_STRONG }};
    }

    /* The headline figures, as tiles. `border-spacing` rather than white borders between cells:
       mpdf collapses a zero-width border and the tiles would run together into one band. */
    table.summary { width: 100%; border-collapse: separate; border-spacing: 5pt 0; }
    table.summary td { background: {{ T::PANEL }}; padding: 9pt 11pt; vertical-align: top; }
    .stat-label {
        font-size: 7pt;
        color: {{ T::MUTED }};
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        font-weight: bold;
    }
    .stat-value { font-size: 12.5pt; font-weight: bold; color: {{ T::INK }}; margin-top: 3pt; white-space: nowrap; }
    .stat-value.warn { color: {{ T::DUE }}; }

    /* A compact listing — denser than `table.items`, because a statement carries many rows of
       short facts rather than a handful of priced lines. */
    table.data { width: 100%; border-collapse: collapse; }
    table.data thead th {
        background: {{ T::PANEL }};
        color: {{ T::INK }};
        text-align: {{ $start }};
        padding: 5pt 6pt;
        font-size: 7.5pt;
        font-weight: bold;
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        border-bottom: 0.8pt solid {{ T::RULE_STRONG }};
    }
    table.data thead th.num { text-align: {{ $end }}; }
    table.data tbody td {
        padding: 5pt 6pt;
        border-bottom: 0.4pt solid {{ T::RULE }};
        vertical-align: top;
        font-size: 8.5pt;
    }
    table.data tbody td.num { text-align: {{ $end }}; white-space: nowrap; }
    table.data tbody td.muted { color: {{ T::MUTED }}; }
    table.data tbody td.due { color: {{ T::DUE }}; font-weight: bold; }
    table.data tbody td.settled { color: {{ T::SETTLED }}; font-weight: bold; }
    table.data tfoot td {
        padding: 6pt;
        font-weight: bold;
        color: {{ T::INK }};
        border-top: 1.2pt solid {{ T::INK }};
        background: {{ T::PANEL }};
    }
    table.data tfoot td.num { text-align: {{ $end }}; white-space: nowrap; }
    table.data tfoot td.due { color: {{ T::DUE }}; }
    table.data tfoot td.settled { color: {{ T::SETTLED }}; }

    /* A section with nothing in it says so. Left blank, the reader cannot tell an empty ledger
       from a document that failed to render one. */
    .empty {
        color: {{ T::MUTED }};
        font-size: 9pt;
        text-align: center;
        padding: 12pt;
        background: {{ T::PANEL }};
    }

    /* ── Panels ──────────────────────────────────────────────────────────────────────────────
       Notes, payment instructions, terms, a tax reference. One treatment, so five different kinds
       of small print do not become five different boxes. */
    .panel {
        background: {{ T::PANEL }};
        border-{{ $start }}: 2pt solid {{ T::RULE_STRONG }};
        padding: 9pt 12pt;
        font-size: 9pt;
        color: {{ T::BODY }};
        margin-bottom: 10pt;
    }
    .panel.accent { background: {{ T::ACCENT_TINT }}; border-{{ $start }}-color: {{ T::ACCENT }}; }
    .panel .label { margin-bottom: 3pt; }
    .panel .mono { font-family: monospace; font-size: 8.5pt; color: {{ T::ACCENT }}; }

    /* A tinted strip of key/value pairs — a period, a reference, a scope. */
    .strip {
        background: {{ T::PANEL }};
        padding: 8pt 12pt;
        font-size: 9pt;
        color: {{ T::BODY }};
    }
    .strip .label { display: inline-block; margin-{{ $end }}: 6pt; }

    /* ── Closing note ────────────────────────────────────────────────────────────────────────
       The operator's own words at the foot of the body — distinct from the RUNNING footer, which
       is page furniture and belongs to the renderer. */
    .closing {
        border-top: 0.4pt solid {{ T::RULE }};
        padding-top: 8pt;
        margin-top: 20pt;
        font-size: 8.5pt;
        color: {{ T::MUTED }};
        text-align: center;
        line-height: 1.5;
    }

    /* ── Signature block ─────────────────────────────────────────────────────────────────────
       For the documents that are countersigned: a purchase order, a withholding certificate. */
    .signatures { width: 100%; border-collapse: collapse; margin-top: 24pt; }
    .signatures td { width: 50%; padding: 0; padding-{{ $end }}: 24pt; vertical-align: bottom; }
    .signatures td.last { padding-{{ $end }}: 0; }
    /* The line a supplier signs above. Two things had to go: an EMPTY div's height collapses in
       mpdf, and `font-size: 0` collapses the line box even with a non-breaking space in it — so the
       rule rendered nowhere both times. Padding on a block with real content is what reserves the
       space, which is why the markup carries an `&nbsp;`. */
    .sig-rule { border-bottom: 0.6pt solid {{ T::RULE_STRONG }}; padding-top: 24pt; }
    .sig-caption { font-size: 8pt; color: {{ T::MUTED }}; padding-top: 4pt; }

    /* A document number, an IBAN, a transaction id — a code the reader will compare character by
       character against another copy of it. */
    .mono { font-family: monospace; font-size: 8pt; }

    /* Two panels side by side — payment instructions beside terms. The gutter is on the FIRST
       cell only, so a single panel spans the full width with no stray padding. */
    .panel-pair { vertical-align: top; padding: 0; padding-{{ $end }}: 10pt; }
    .panel-pair.only { padding-{{ $end }}: 0; }

    .nowrap { white-space: nowrap; }
    .ink { color: {{ T::INK }}; }
    .muted { color: {{ T::MUTED }}; }
    .num { text-align: {{ $end }}; white-space: nowrap; }
    .start { text-align: {{ $start }}; }
    .end { text-align: {{ $end }}; }
</style>
