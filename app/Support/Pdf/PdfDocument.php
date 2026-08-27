<?php

namespace App\Support\Pdf;

use Closure;
use Illuminate\Support\Facades\View;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * **One renderer for every PDF this system issues.**
 *
 * All thirteen `*PdfService` classes ended in the same twenty lines: derive `$isRtl` from the
 * ambient locale, render a blade, construct an `Mpdf` with the same nine options, set the
 * directionality, return the string. Thirteen copies of one decision, which is how they had already
 * drifted — two used 14mm margins where eleven used 12, two set 10pt where eleven set 10.5, and the
 * font pair `xbriyaz`/`dejavusans` was repeated verbatim in all of them. A change to how these
 * documents are typeset was a thirteen-file edit that nobody would make, so nobody did.
 *
 * ## The font
 *
 * mpdf bundles exactly one Arabic face, **XB Riyaz**, and it is not a document font — it is a
 * Persian display family whose joins break in ordinary Egyptian vocabulary. Measured on the shipped
 * invoice: «تاريخ الاستحقاق» rendered with the ر detached, on the due-date line of the document a
 * tenant reads every month. The English half was a different family again (DejaVu Sans), so the two
 * languages of one invoice were not the same document with the words changed — they were two
 * documents.
 *
 * **IBM Plex Sans Arabic** replaces both. One family, drawn as one design across Arabic and Latin,
 * so the two renderings of a document are the same typeface at the same weight, and a bilingual
 * line ("Unit A-04 · الطابق G") is set in one voice rather than two. It is OFL-licensed and lives in
 * `resources/fonts/`, checked in — a document font cannot be a `composer`-time or CDN dependency,
 * because a box that cannot fetch it does not fail loudly, it silently issues invoices in a
 * substituted face.
 *
 * `autoLangToFont` is deliberately OFF. mpdf's script detection exists to swap fonts for a family
 * that cannot render a run; ours renders both scripts, and leaving detection on lets it swap
 * anyway — the exact drift the single family was chosen to end. `useSubstitutions` stays on as the
 * floor for a character no font here covers (a Chinese trade name in a tenant register), which
 * substitutes per GLYPH rather than per run.
 *
 * ## Page furniture
 *
 * Every document gets a running footer carrying its own reference and `page x of y`. This is not
 * decoration: a tenant statement runs to several pages, gets printed, and a loose sheet with no
 * document number on it cannot be filed or challenged. `x of y` rather than a bare page number, so
 * a reader can tell a three-page statement from its first page — and it prints on a one-page
 * invoice too, as "1 / 1", which is what SAP, Oracle and Yardi all put there for the same reason.
 *
 * @see DocumentLocale for which language a document is written in — this class only renders it.
 */
final class PdfDocument
{
    /**
     * The document family, registered with mpdf under this key.
     *
     * A name, not a path, because it is what the shared stylesheet's `font-family` names; the two
     * must agree and there is no third place that spells it.
     */
    public const FONT = 'plexsansarabic';

    /** Body size in points. The two services that set 10 were narrower tables, not a decision. */
    private float $fontSize = 10.5;

    /** @var array{left: float, right: float, top: float, bottom: float} in millimetres */
    private array $margins = ['left' => 13, 'right' => 13, 'top' => 12, 'bottom' => 16];

    private ?string $locale = null;

    private ?string $reference = null;

    private Closure|string|null $watermark = null;

    /** @var Closure(): array<string, mixed>|array<string, mixed> */
    private Closure|array $data = [];

    private function __construct(private readonly string $view) {}

    public static function make(string $view): self
    {
        return new self($view);
    }

    /**
     * The view data, or a closure producing it.
     *
     * A CLOSURE is the form to reach for whenever the data is derived: it runs inside the locale
     * ({@see DocumentLocale::in()}), so a service that picks `name_ar` over `name_en`, or composes a
     * `__()` label into its payload, resolves in the document's language rather than the operator's.
     * An array is evaluated by the caller and is only safe when it holds no translated text.
     *
     * @param  Closure(): array<string, mixed>|array<string, mixed>  $data
     */
    public function data(Closure|array $data): self
    {
        $this->data = $data;

        return $this;
    }

    /** The language the document is written in. Null keeps whatever the request is already in. */
    public function locale(?string $locale): self
    {
        $this->locale = $locale;

        return $this;
    }

    /**
     * What the running footer calls this document — an invoice number, a statement period.
     *
     * Omitted, the footer still carries `page x of y`: knowing which page you are holding is worth
     * having even when the document has no reference to state.
     */
    public function reference(?string $reference): self
    {
        $this->reference = filled($reference) ? $reference : null;

        return $this;
    }

    /**
     * Stamp the page diagonally — for a document that is no longer live.
     *
     * A cancelled invoice and a live one differed by a small status pill in the header, so a printed
     * void document was indistinguishable from a payable one at arm's length. That is the one thing
     * a document must never be ambiguous about, and a watermark is how every accounting package
     * says it.
     *
     * Takes a CLOSURE as well as a string, and every caller passing translated text must use one:
     * the stamp is a word — "CANCELLED", «ملغاة» — and a `__()` evaluated at the call site resolves
     * in the operator's language, stamping an Arabic invoice in English. Deferring it to
     * {@see render()} puts it inside the document's locale with everything else.
     *
     * @param  Closure(): (string|null)|string|null  $text
     */
    public function watermark(Closure|string|null $text): self
    {
        $this->watermark = $text;

        return $this;
    }

    /** @param array{left?: float, right?: float, top?: float, bottom?: float} $margins */
    public function margins(array $margins): self
    {
        $this->margins = [...$this->margins, ...$margins];

        return $this;
    }

    public function fontSize(float $points): self
    {
        $this->fontSize = $points;

        return $this;
    }

    /** The rendered PDF, as bytes. */
    public function render(): string
    {
        $locale = $this->locale ?? DocumentLocale::resolve();

        return DocumentLocale::in($locale, function () use ($locale): string {
            $isRtl = DocumentLocale::isRtl($locale);
            $data = $this->data instanceof Closure ? ($this->data)() : $this->data;

            // `isRtl` is passed rather than left to each template to derive, so a template cannot
            // answer the direction question differently from the renderer that set the page up.
            // Templates that already compute it themselves are unaffected — the locale is set, so
            // their own `app()->getLocale()` agrees with this.
            $html = View::make($this->view, [...$data, 'isRtl' => $isRtl])->render();

            $mpdf = $this->mpdf($isRtl);
            $mpdf->SetDirectionality($isRtl ? 'rtl' : 'ltr');
            $mpdf->SetHTMLFooter($this->footer($isRtl));

            $watermark = $this->watermark instanceof Closure ? ($this->watermark)() : $this->watermark;

            if (filled($watermark)) {
                $mpdf->SetWatermarkText($watermark);
                $mpdf->showWatermarkText = true;
                // Light enough to read the figures through, dark enough to see across a desk.
                $mpdf->watermarkTextAlpha = 0.08;
                $mpdf->watermark_font = self::FONT;
            }

            $mpdf->WriteHTML($html);

            return $mpdf->Output('', Destination::STRING_RETURN);
        });
    }

    private function mpdf(bool $isRtl): Mpdf
    {
        $tempDir = storage_path('app/mpdf');

        if (! is_dir($tempDir)) {
            @mkdir($tempDir, 0775, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => $this->margins['left'],
            'margin_right' => $this->margins['right'],
            'margin_top' => $this->margins['top'],
            'margin_bottom' => $this->margins['bottom'],
            // Room for the running footer, which sits below the bottom margin.
            'margin_footer' => 8,
            'directionality' => $isRtl ? 'rtl' : 'ltr',
            'default_font' => self::FONT,
            'default_font_size' => $this->fontSize,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], resource_path('fonts')],
            'fontdata' => [...(new FontVariables)->getDefaults()['fontdata'], ...self::fontData()],
            'autoScriptToLang' => true,
            'autoArabic' => true,
            // See the class docblock: one family covers both scripts, so letting mpdf swap fonts by
            // script is the drift this replaced, not a safety net.
            'autoLangToFont' => false,
            'useSubstitutions' => true,
            'tempDir' => $tempDir,
        ]);
    }

    /**
     * The one place the font files are named.
     *
     * SemiBold rather than Bold in the `B` slot on purpose: at 9–11pt, Plex Bold is heavier than a
     * financial document wants for a column heading, and the weight that reads as "emphasis" on a
     * printed statement is 600. Bold stays available to the stylesheet as its own family for the
     * places that genuinely need the extra weight — a grand total, a document title.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function fontData(): array
    {
        return [
            self::FONT => [
                'R' => 'IBMPlexSansArabic-Regular.ttf',
                'B' => 'IBMPlexSansArabic-SemiBold.ttf',
                // OpenType layout is what joins Arabic glyphs; without it the script renders as
                // disconnected letterforms. 0xFF is every feature mpdf implements.
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ],
            self::FONT.'heavy' => [
                'R' => 'IBMPlexSansArabic-Bold.ttf',
                'B' => 'IBMPlexSansArabic-Bold.ttf',
                'useOTL' => 0xFF,
                'useKashida' => 75,
            ],
        ];
    }

    /**
     * The running footer: the document's own reference on the inner edge, the page count opposite.
     *
     * Built as a table because mpdf lays footers out as a block and a float would collapse. The
     * `{PAGENO}` / `{nbpg}` placeholders are mpdf's, substituted after the page count is known.
     */
    private function footer(bool $isRtl): string
    {
        $start = $isRtl ? 'right' : 'left';
        $end = $isRtl ? 'left' : 'right';
        $reference = $this->reference !== null ? e($this->reference) : '';

        return <<<HTML
            <table style="width:100%;border-top:0.4pt solid #DDD8CE;padding-top:3mm;
                          font-family:{$this->fontFamily()};font-size:7.5pt;color:#9A948A;">
                <tr>
                    <td style="text-align:{$start};">{$reference}</td>
                    <td style="text-align:{$end};">{PAGENO} / {nbpg}</td>
                </tr>
            </table>
            HTML;
    }

    private function fontFamily(): string
    {
        return self::FONT;
    }
}
