# Document fonts

**IBM Plex Sans Arabic** — the family every PDF this system issues is set in, and the only font
`App\Support\Pdf\PdfDocument` registers with mpdf.

## Why it is checked in

A document font cannot be a `composer`-time or CDN dependency. A box that cannot fetch it does not
fail loudly — mpdf substitutes, and the install quietly starts issuing invoices in a face nobody
chose. `PdfLocaleConformanceTest` asserts every registered file is readable on disk for that reason.

## Why this family

mpdf bundles exactly one Arabic face, **XB Riyaz**, and it is not a document font — it is a Persian
display family whose joins break in ordinary Egyptian vocabulary. Measured on the shipped invoice:
«تاريخ الاستحقاق» rendered with the ر detached, on the due-date line of the document a tenant reads
every month. The English half was a different family again (DejaVu Sans), so the two languages of one
invoice were not the same document with the words changed — they were two documents.

Plex Sans Arabic is drawn as one design across Arabic and Latin, so a bilingual line
("Unit A-04 · الطابق G") is set in one voice. That is also why `autoLangToFont` is OFF in the
renderer: mpdf's script detection exists to swap fonts for a family that cannot render a run, and
this one renders both.

`SemiBold` fills the `B` slot rather than `Bold`: at 9–11pt, Plex Bold is heavier than a financial
document wants for a column heading. `Bold` stays registered as its own family for a grand total or
a document title.

## Licence

SIL Open Font License 1.1 — see `OFL.txt`. Redistribution is permitted; the licence file must travel
with the fonts, which is why it is beside them here.

## Updating

Replace the `.ttf` files and delete `storage/app/mpdf/ttfontdata/` so mpdf rebuilds its metric cache;
the next render regenerates it. Adding a weight means adding it to `PdfDocument::fontData()`, which
is the one place the filenames are named.
