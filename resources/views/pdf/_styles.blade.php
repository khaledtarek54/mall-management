{{--
    The stylesheet EVERY issued document is set in.

    Six templates carried ~150 near-identical lines of this each, and they had already drifted: two
    used a 2px accent rule where four used 3px, the muted grey was spelled three ways, and the
    receipt's table cells were 2px tighter than the invoice's for no reason anyone could name. One
    stylesheet is the point — and it is what made adopting a whole new direction a change to THIS
    file rather than to twelve.

    **Direction D** (chosen 2026-08-28 from four drawn side by side in both languages): a full-bleed
    band carries the mall's identity, everything below it is white paper with hairlines, and the
    accent is spent on the one figure the reader owes.

    NO `@page` rule here, deliberately. Page geometry — size, margins, the strip the running footer
    sits in, and whether the page bleeds — belongs to `App\Support\Pdf\PdfDocument`, which is the
    thing that also knows there IS a footer. Every template used to set its own `@page { margin: … }`
    in px, which silently overrode mpdf's own margins and left no room beneath the body, so the
    running footer these documents now carry rendered nowhere at all until the rules were removed.

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
    $track = $isRtl ? '0' : '0.14em';
    $caps = $isRtl ? 'none' : 'uppercase';
    // Arabic sets smaller than Latin at the same point size — lower x-height, no capitals — so a
    // caption that reads as a label in English reads as a whisper in Arabic. One step up, once.
    $labelSize = $isRtl ? '8.5pt' : '7pt';
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

    /* ── The band ────────────────────────────────────────────────────────────────────────────
       Direction D's signature, and the only large ink field on the page: the mall is recognisable
       across a desk before a word is read. It runs to the paper edge, which is why the renderer
       drops the page margins for any document built on this shell (`PdfDocument::bleed()`) and the
       body below supplies its own. An inset band would be a coloured box, not a masthead — the
       whole difference is the bleed. */
    .band {
        background: {{ T::INK }};
        color: {{ T::REVERSED }};
        padding: 13mm 13mm 11mm;
    }
    .band table { width: 100%; border-collapse: collapse; }
    .band td { vertical-align: top; padding: 0; }
    .band .issuer { width: 58%; }
    .band .document { width: 42%; text-align: {{ $end }}; }

    .issuer-name {
        font-size: 17pt;
        font-weight: bold;
        color: {{ T::REVERSED }};
        line-height: 1.2;
    }
    /* BAND_MUTED is legible on the band and nowhere else — the one colour in the palette with a
       single permitted background. */
    .issuer-line { color: {{ T::BAND_MUTED }}; font-size: 8.5pt; line-height: 1.5; }
    .issuer-line strong { color: {{ T::REVERSED }}; font-weight: bold; }

    /* A mall's logo is drawn for white paper. Reversed onto navy an ordinary one disappears or
       shows its bounding box, so it gets a plate of its own rather than a redesign we cannot do on
       the operator's behalf. */
    /* A SPAN, never a div: mpdf implements no `display: inline-block` at all, so a div carrying
       this rule is laid out as a block and paints a white bar across the whole navy band. An inline
       background is the one thing mpdf does honour here — the same reason `.chip` works. */
    .logo-plate {
        background: {{ T::REVERSED }};
        padding: 2mm 3mm;
        border-radius: 1.5pt;
    }

    .doc-type {
        font-size: {{ $isRtl ? '12pt' : '10pt' }};
        font-weight: bold;
        color: {{ T::ACCENT }};
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        line-height: 1.2;
    }
    .doc-number {
        /* Bold, not the SemiBold the `bold` keyword resolves to: this and the balance figure are
           Direction D's two focal points, and the weight is what makes them read as such across a
           desk. The heavy family exists for these two rules and nothing else. */
        font-family: {{ \App\Support\Pdf\PdfDocument::FONT }}heavy;
        font-size: 15pt;
        font-weight: bold;
        color: {{ T::REVERSED }};
        margin-top: 2pt;
    }
    .doc-meta { color: {{ T::BAND_MUTED }}; font-size: 8.5pt; line-height: 1.5; }
    .doc-meta strong { color: {{ T::REVERSED }}; font-weight: bold; }

    /* The chip that rides in the band. Reversed rather than tinted: a pale chip on navy disappears,
       and the states that reach a masthead are the ones a reader must not miss. */
    .band-chip {
        display: inline-block;
        margin-top: 4mm;
        /* Arabic carries tashkeel above and descenders below the box a Latin cap-height padding
           reserves, so «مدفوعة جزئيًا» had its diacritics clipped by the chip's own edge. */
        padding: {{ $isRtl ? '5pt 9pt' : '3pt 8pt' }};
        line-height: {{ $isRtl ? '1.5' : '1.35' }};
        border-radius: 1.5pt;
        font-size: {{ $isRtl ? '9pt' : '7.5pt' }};
        font-weight: bold;
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
    }

    /* Anything reusing the paper label style INSIDE the band has to be reversed with it — MUTED is
       a paper colour and is unreadable on navy. One override rather than a second class the six
       templates would each have to remember. */
    .band .label { color: {{ T::BAND_MUTED }}; }

    /* The strip that carries the band's identity onto pages 2+. Slim on purpose: repeating the
       full masthead would double the ink on every multi-page statement, and what a continuation
       page actually needs is to say which document it belongs to. */
    .continuation {
        background: {{ T::INK }};
        color: {{ T::BAND_MUTED }};
        padding: 4mm 13mm;
        font-size: 8pt;
        text-align: {{ $start }};
    }

    /* ── The body ────────────────────────────────────────────────────────────────────────────
       Supplies the horizontal margin the bleeding page no longer has. */
    .page-body { padding: 9mm 13mm 0; }

    /* ── Labels ────────────────────────────────────────────────────────────────────────────── */
    .label {
        font-size: {{ $labelSize }};
        color: {{ T::MUTED }};
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        font-weight: bold;
        line-height: 1.4;
    }

    /* ── Facts strip ─────────────────────────────────────────────────────────────────────────
       Who is billed, against what agreement, on what dates — one band rather than two 50% blocks
       plus a separate full-width period bar. Same information, and the dates stop being an
       afterthought at the bottom. */
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
        font-size: 11pt;
        font-weight: bold;
        line-height: 1.35;
        margin-top: 3pt;
        margin-bottom: 1pt;
    }
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
    .facts .pair td.v { color: {{ T::INK }}; text-align: {{ $end }}; white-space: nowrap; }

    /* ── Status chip, on paper ───────────────────────────────────────────────────────────── */
    .chip {
        display: inline-block;
        padding: {{ $isRtl ? '5pt 9pt' : '3pt 8pt' }};
        line-height: {{ $isRtl ? '1.5' : '1.35' }};
        border-radius: 1.5pt;
        font-size: {{ $isRtl ? '9pt' : '7.5pt' }};
        font-weight: bold;
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
    }

    /* ── Line items ──────────────────────────────────────────────────────────────────────────
       A TINTED heading, not a dark bar: the band above is already the page's one ink field, and a
       second solid block halfway down would compete with it. The heading REPEATS on every page —
       mpdf carries <thead> over a break, which is the whole reason it is a real thead and not a
       styled first row. */
    table.items { width: 100%; border-collapse: collapse; }
    table.items thead th {
        background: {{ T::PANEL }};
        color: {{ T::INK }};
        text-align: {{ $start }};
        padding: 7pt 9pt;
        font-size: {{ $labelSize }};
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

    /* A SUPPORTING table — a VAT split that explains the table above rather than standing beside
       it. Two tinted bands stacked ten points apart read as two documents. */
    table.items.secondary thead th {
        background: transparent;
        border-bottom: 0.8pt solid {{ T::RULE_STRONG }};
    }

    /* ── Statement furniture ─────────────────────────────────────────────────────────────────
       A statement is several LISTINGS under headings, where an invoice is one. Shared because four
       documents need the same pieces and each had its own copy. */
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
        font-size: {{ $labelSize }};
        color: {{ T::MUTED }};
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        font-weight: bold;
    }
    .stat-value { font-size: 12.5pt; font-weight: bold; color: {{ T::INK }}; margin-top: 3pt; white-space: nowrap; }
    .stat-value.warn { color: {{ T::DUE }}; }

    /* A compact listing — denser than `table.items`, because a statement carries many rows of short
       facts rather than a handful of priced lines. */
    table.data { width: 100%; border-collapse: collapse; }
    table.data thead th {
        background: {{ T::PANEL }};
        color: {{ T::INK }};
        text-align: {{ $start }};
        padding: 5pt 6pt;
        font-size: {{ $isRtl ? '8pt' : '7.5pt' }};
        font-weight: bold;
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        border-bottom: 0.8pt solid {{ T::RULE_STRONG }};
    }
    table.data thead th.num { text-align: {{ $end }}; }
    table.data td {
        padding: 5pt 6pt;
        border-bottom: 0.4pt solid {{ T::RULE }};
        vertical-align: top;
        font-size: 8.5pt;
    }
    table.data td.num { text-align: {{ $end }}; white-space: nowrap; }
    table.data td.muted { color: {{ T::MUTED }}; }
    table.data td.due { color: {{ T::DUE }}; font-weight: bold; }
    table.data td.settled { color: {{ T::SETTLED }}; font-weight: bold; }
    /* A LEDGER writes label/figure pairs rather than columns: `k` names the thing, `v` is the money.
       Both ledgers migrated onto `table.data` carrying these cells, and without the pair every
       figure on a CAM reconciliation printed left-aligned in body weight beside its own label. */
    table.data td.k { color: {{ T::BODY }}; }
    table.data td.v {
        text-align: {{ $end }};
        color: {{ T::INK }};
        font-weight: bold;
        white-space: nowrap;
    }
    /* A true-up the mall owes BACK to the tenant. The class survived the migration and its colour
       did not, so the single most consequential figure on an over-recovered reconciliation was set
       in the same ink as a demand. */
    table.data td.credit, .credit { color: {{ T::SETTLED }}; }
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

    /* A section with nothing in it says so. Left blank, the reader cannot tell an empty ledger from
       a document that failed to render one. */
    .empty {
        color: {{ T::MUTED }};
        font-size: 9pt;
        text-align: center;
        padding: 12pt;
        background: {{ T::PANEL }};
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
    table.totals td.v { color: {{ T::INK }}; text-align: {{ $end }}; white-space: nowrap; }
    table.totals tr.rule td { border-top: 0.4pt solid {{ T::RULE }}; }

    /* Every emphasised row states the colour on BOTH cells rather than on the row. mpdf's cascade
       is an approximation of the real one, and a `tr.grand td` rule did NOT beat the `td.v` rule it
       outranks in a browser — so the grand total's FIGURE rendered in body grey on a near-black
       band, the least legible thing on the page and the one number the reader came for. */
    table.totals tr.grand td.k,
    table.totals tr.grand td.v {
        background: {{ T::INK }};
        color: {{ T::REVERSED }};
        font-weight: bold;
        font-size: 11.5pt;
        padding: 8pt 9pt;
    }
    /* The tax-inclusive total on a document whose BALANCE gets the panel below: emphasised with a
       rule and weight rather than a fill, so the panel stays the loudest thing on the page. */
    table.totals tr.subtotal td.k,
    table.totals tr.subtotal td.v {
        border-top: 0.4pt solid {{ T::RULE_STRONG }};
        font-weight: bold;
        color: {{ T::INK }};
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

    /* ── The balance panel ───────────────────────────────────────────────────────────────────
       Direction D's other half: the one figure the reader came for, set apart on the accent at a
       size nothing else competes with. The accent is spent HERE and nowhere else on paper. */
    .balance {
        width: 100%;
        border-collapse: collapse;
        background: {{ T::ACCENT_TINT }};
        border: 1.2pt solid {{ T::ACCENT }};
        margin-top: 12pt;
    }
    .balance td { padding: 10pt 13pt; vertical-align: middle; }
    .balance .caption { color: {{ T::MUTED }}; font-size: 8.5pt; margin-top: 2pt; }
    .balance .figure {
        font-family: {{ \App\Support\Pdf\PdfDocument::FONT }}heavy;
        text-align: {{ $end }};
        font-size: 17pt;
        font-weight: bold;
        color: {{ T::INK }};
        white-space: nowrap;
    }

    /* ── Panels ──────────────────────────────────────────────────────────────────────────────
       Notes, payment instructions, terms, a tax reference. One treatment, so five different kinds
       of small print do not become five different boxes. */
    .panel {
        background: {{ T::PANEL }};
        padding: 9pt 12pt;
        font-size: 9pt;
        color: {{ T::BODY }};
        margin-bottom: 10pt;
    }
    .panel.accent { background: {{ T::ACCENT_TINT }}; }
    .panel .label { margin-bottom: 3pt; }
    .panel .mono { font-family: monospace; font-size: 8.5pt; color: {{ T::INK }}; }

    /* Two panels side by side — payment instructions beside terms. The gutter is on the FIRST cell
       only, so a single panel spans the full width with no stray padding. */
    .panel-pair { vertical-align: top; padding: 0; padding-{{ $end }}: 10pt; }
    .panel-pair.only { padding-{{ $end }}: 0; }

    /* A tinted strip of key/value pairs — a period, a reference, a scope. */
    .strip {
        background: {{ T::PANEL }};
        padding: 8pt 12pt;
        font-size: 9pt;
        color: {{ T::BODY }};
    }
    .strip .label { display: inline-block; margin-{{ $end }}: 6pt; }

    /* ── Closing note ────────────────────────────────────────────────────────────────────────
       The operator's own words at the foot of the body — distinct from the RUNNING footer, which is
       page furniture and belongs to the renderer. */
    .closing {
        border-top: 0.4pt solid {{ T::RULE }};
        padding-top: 8pt;
        margin-top: 20pt;
        padding-bottom: 6mm;
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

    /* ── Ledger listings ─────────────────────────────────────────────────────────────────────
       The owner statement and the CAM reconciliation are LEDGERS: a run of rows under section
       headings, with subtotals inside the run rather than at the end. They carried their own copies
       of these four shapes; these are the shared ones. */
    tr.section-row td, td.section-row {
        background: {{ T::PANEL }};
        color: {{ T::INK }};
        font-weight: bold;
        font-size: {{ $labelSize }};
        letter-spacing: {{ $track }};
        text-transform: {{ $caps }};
        padding: 6pt;
        border-bottom: 0.4pt solid {{ T::RULE_STRONG }};
    }
    tr.subtotal td, td.subtotal {
        font-weight: bold;
        color: {{ T::INK }};
        border-top: 0.8pt solid {{ T::RULE_STRONG }};
    }
    tr.net td, td.net {
        font-weight: bold;
        color: {{ T::INK }};
        border-top: 1.2pt solid {{ T::INK }};
        background: {{ T::PANEL }};
    }
    .sub td, td.sub { color: {{ T::MUTED }}; font-size: 8.5pt; padding-{{ $start }}: 16pt; }
    .sub { color: {{ T::MUTED }}; font-size: 8.5pt; }
    .basis, .note { color: {{ T::MUTED }}; font-size: 8.5pt; }
    /* The operator's own words at the foot of a ledger — the `.closing` treatment without the
       shell's top rule, because these documents close with a table that already has one. */
    .closing-note {
        margin-top: 14pt;
        padding-bottom: 6mm;
        font-size: 8.5pt;
        color: {{ T::MUTED }};
        line-height: 1.5;
    }

    /* A document number, an IBAN, a transaction id — a code the reader compares character by
       character against another copy of it. */
    .mono { font-family: monospace; font-size: 8pt; }

    .nowrap { white-space: nowrap; }
    .ink { color: {{ T::INK }}; }
    .muted { color: {{ T::MUTED }}; }
    .num { text-align: {{ $end }}; white-space: nowrap; }
    .start { text-align: {{ $start }}; }
    .end { text-align: {{ $end }}; }

    /*
    | NOTHING IN A DOCUMENT IS EVER ITALIC, AND THAT IS A CORRECTNESS RULE.
    |
    | IBM Plex Sans Arabic ships no italic face, and mpdf does not degrade to the upright one — it
    | falls through to a font with NO ARABIC COVERAGE, so «فاتورة ضريبية» inside an `<em>` renders
    | as a row of empty boxes while the Latin beside it slopes correctly. Measured on mpdf 8.x:
    | plain and bold render, `<em>`, `<i>` and `font-style: italic` all lose the script entirely.
    | Declaring `I`/`BI` in `PdfDocument::fontData()` does NOT fix it — that was tried and
    | reverted rather than shipped as a fix that does not fix.
    |
    | No shipped template used italic when this was found, so the bug was latent. This makes it
    | unreachable: emphasis becomes WEIGHT, which is also the right typographic answer, because
    | Arabic has no italic tradition to borrow.
    */
    em, i, cite, dfn, var, address { font-style: normal; font-weight: 600; }
</style>
