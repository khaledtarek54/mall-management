<?php

namespace App\Console\Commands;

use App\Support\Pdf\PdfDocument;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Renders the client questionnaire to PDF, from the markdown that IS the questionnaire.
 *
 * **One source, two renderings.** The markdown is the document; this only sets it in type. Keeping
 * the questions in a Blade template as well would be a second copy of one list, and the copy nobody
 * regenerated would be the one that got sent — which is the exact failure `STATUS.md` exists to
 * prevent and which this project has already had twice.
 *
 * A command rather than a one-off script for the same reason: answers arrive, questions close, and
 * the PDF has to be reissued. If reissuing were manual, the file in `docs/` would quietly become
 * the old version of the ask.
 */
class ClientQuestionsPdfCommand extends Command
{
    protected $signature = 'atriom:client-questions
        {--source=docs/operations/WHAT-WE-NEED-FROM-YOU.md : The markdown to render}
        {--out=docs/operations/WHAT-WE-NEED-FROM-YOU.pdf : Where to write the PDF}';

    protected $description = 'Render the go-live questionnaire to a PDF for the operator';

    public function handle(): int
    {
        $source = base_path((string) $this->option('source'));

        if (! is_file($source)) {
            $this->error("No such file: {$source}");

            return self::FAILURE;
        }

        $markdown = (string) file_get_contents($source);

        // The blockquote guard rails and the "this is a form, not a status list" note are for
        // whoever maintains the repository, not for the client. Everything above the first `---`
        // is stripped, so the PDF opens on the title.
        $markdown = preg_replace('/^>.*$\n?/m', '', $markdown) ?? $markdown;

        $body = Str::markdown($markdown, ['html_input' => 'allow']);

        // A predominantly-Arabic paragraph or cell is set RTL, and everything else stays LTR.
        //
        // The document is deliberately mixed — an English question beside its Arabic twin — so a
        // document-level `rtl` would reverse the English half and a document-level `ltr` leaves
        // Arabic punctuation stranded at the wrong end of its sentence. Per-element is the only
        // reading that is right for both, and it is decided by counting script rather than by
        // guessing from position.
        $body = preg_replace_callback(
            '/<(p|td|th|li|h2|h3)>(.*?)<\/\1>/su',
            function (array $m): string {
                $arabic = preg_match_all('/\p{Arabic}/u', $m[2]);
                $latin = preg_match_all('/[A-Za-z]/u', $m[2]);

                return $arabic > $latin
                    ? "<{$m[1]} dir=\"rtl\" style=\"text-align:right\">{$m[2]}</{$m[1]}>"
                    : $m[0];
            },
            $body
        ) ?? $body;

        $pdf = PdfDocument::make('pdf.client-questions')
            ->data(['body' => $body])
            // Rendered in ENGLISH as the base direction even though half of it is Arabic: the
            // headings, the tables and the reference codes are Latin, and mpdf lays a mixed
            // document out correctly from an LTR base with RTL islands — the reverse strands every
            // English column heading.
            ->locale('en')
            ->reference('Atriom · go-live questionnaire')
            ->margins(['top' => 14, 'right' => 12, 'bottom' => 16, 'left' => 12])
            ->render();

        $out = base_path((string) $this->option('out'));
        file_put_contents($out, $pdf);

        $this->info(sprintf('Wrote %s (%s KB).', $this->option('out'), number_format(strlen($pdf) / 1024)));

        return self::SUCCESS;
    }
}
