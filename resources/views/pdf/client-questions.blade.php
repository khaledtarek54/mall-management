{{--
| The client questionnaire, rendered from its markdown source.
|
| Deliberately NOT on `pdf.layout`: that shell is Direction D — a full-bleed navy masthead and an
| amber figure panel — which is the identity of a DOCUMENT THIS BUSINESS ISSUES. This is a working
| form we hand someone to fill in, not an invoice, and dressing it as one would misrepresent what it
| is. It keeps the same typeface (so Arabic shapes correctly) and the same restraint, and nothing
| else.
|
| No `@page` rule anywhere: page geometry belongs to the renderer, and a `@page` here would silently
| override mpdf's margins and leave no room for the running footer.
--}}
<style>
    body { font-family: 'IBM Plex Sans Arabic', sans-serif; font-size: 9.5pt; line-height: 1.5; color: #1f2937; }
    h1 { font-size: 17pt; margin: 0 0 2mm; color: #0f172a; }
    h2 { font-size: 12pt; margin: 7mm 0 2mm; padding-bottom: 1.5mm; border-bottom: 0.4mm solid #0f172a; color: #0f172a; }
    h3 { font-size: 10.5pt; margin: 5mm 0 1.5mm; color: #0f172a; }
    p { margin: 0 0 2.5mm; }
    table { width: 100%; border-collapse: collapse; margin: 2mm 0 4mm; }
    th { background: #0f172a; color: #ffffff; text-align: start; padding: 1.6mm 2mm; font-size: 8.5pt; }
    td { border-bottom: 0.2mm solid #d1d5db; padding: 1.6mm 2mm; font-size: 8.5pt; vertical-align: top; }
    blockquote { margin: 2mm 0 4mm; padding: 2mm 3mm; background: #f8fafc; border-inline-start: 0.8mm solid #b45309; }
    blockquote p { margin: 0; }
    code { background: #f1f5f9; padding: 0.3mm 1mm; font-family: 'IBM Plex Sans Arabic', sans-serif; }
    hr { border: 0; border-top: 0.2mm solid #e5e7eb; margin: 5mm 0; }
    a { color: #1f2937; text-decoration: none; }
    /* Emphasis as weight, never slope — italic loses Arabic entirely in mpdf. See _styles. */
    em, i { font-style: normal; font-weight: 600; }
</style>

{!! $body !!}
