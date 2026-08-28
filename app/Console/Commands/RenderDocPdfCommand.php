<?php

namespace App\Console\Commands;

use App\Support\Pdf\PdfDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\HTMLParserMode;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

/**
 * Render a markdown doc to PDF — for the ones that get handed to someone outside the team.
 *
 * The accountant briefing is bilingual (Arabic sections inline with English), which rules out
 * "print the markdown preview": Arabic needs shaping and RTL runs, and a browser print gives
 * neither reliably inside a mostly-LTR document. mPDF is already a dependency for invoices and
 * handles both, so the same engine renders the hand-out.
 *
 * Deliberately generic rather than briefing-specific: the same need turns up for any doc that
 * leaves the building.
 */
class RenderDocPdfCommand extends Command
{
    protected $signature = 'atriom:doc-pdf
        {source : Path to the markdown file, relative to the project root}
        {--output= : Where to write the PDF (default: alongside the source)}';

    protected $description = 'Render a markdown doc to a PDF hand-out (Arabic/RTL aware)';

    public function handle(): int
    {
        $source = base_path($this->argument('source'));

        if (! is_file($source)) {
            $this->error("Not found: {$source}");

            return self::FAILURE;
        }

        $output = $this->option('output')
            ? base_path($this->option('output'))
            : preg_replace('/\.md$/', '.pdf', $source);

        $markdown = file_get_contents($source);

        // Strip the HTML comment markers used by the doc generators — they are machinery, not
        // content, and would render as stray text in a hand-out.
        $markdown = preg_replace('/<!--.*?-->/s', '', $markdown);

        $html = Str::markdown($markdown, ['html_input' => 'strip']);

        $temp = storage_path('app/mpdf');

        if (! is_dir($temp)) {
            @mkdir($temp, 0775, true);
        }

        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4',
            'margin_left' => 15,
            'margin_right' => 15,
            'margin_top' => 16,
            'margin_bottom' => 18,
            // The same family the issued documents are set in. It matters MORE here than there:
            // this command's whole reason to exist is a briefing written with Arabic runs inside
            // English paragraphs, and the previous pairing — DejaVu Sans plus `autoLangToFont`
            // swapping to mpdf's bundled XB Riyaz for the Arabic — broke the glyph joins on exactly
            // those runs. One family covers both scripts, so nothing is swapped.
            'default_font' => PdfDocument::FONT,
            'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], resource_path('fonts')],
            'fontdata' => [...(new FontVariables)->getDefaults()['fontdata'], ...PdfDocument::fontData()],
            'default_font_size' => 10,
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
            'autoArabic' => true,
            'useSubstitutions' => true,
            'tempDir' => $temp,
        ]);

        $mpdf->SetTitle(Str::headline(pathinfo($source, PATHINFO_FILENAME)));
        $mpdf->WriteHTML($this->stylesheet(), HTMLParserMode::HEADER_CSS);
        $mpdf->WriteHTML($html, HTMLParserMode::HTML_BODY);

        file_put_contents($output, $mpdf->Output('', Destination::STRING_RETURN));

        $this->info('Wrote '.str_replace(base_path().'/', '', $output).' ('.number_format(filesize($output) / 1024).' KB)');

        return self::SUCCESS;
    }

    /** Print-oriented styling: readable tables, no screen-sized headings. */
    private function stylesheet(): string
    {
        return <<<'CSS'
        body  { font-size: 10pt; line-height: 1.45; color: #111; }
        h1    { font-size: 19pt; border-bottom: 2px solid #222; padding-bottom: 5px; margin-top: 0; }
        h2    { font-size: 14pt; margin-top: 17px; border-bottom: 1px solid #bbb; padding-bottom: 3px; }
        h3    { font-size: 11.5pt; margin-top: 13px; }
        table { border-collapse: collapse; width: 100%; font-size: 8.6pt; margin: 9px 0; }
        th    { background: #f0f0f0; text-align: left; font-weight: bold; }
        th, td{ border: 0.4pt solid #999; padding: 4px 6px; vertical-align: top; }
        code  { font-family: monospace; background: #f4f4f4; font-size: 8.6pt; }
        pre   { background: #f6f6f6; border: 0.4pt solid #ccc; padding: 7px; font-size: 8.4pt; }
        blockquote { border-left: 2.5pt solid #888; margin-left: 0; padding-left: 9px; color: #333; }
        CSS;
    }
}
