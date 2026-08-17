<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Report\Clover as CloverReport;
use SebastianBergmann\CodeCoverage\Report\Html\Facade as HtmlReport;
use SebastianBergmann\CodeCoverage\Report\Text as TextReport;
use SebastianBergmann\CodeCoverage\Report\Thresholds;

/**
 * Merges all per-request .cov files written by RecordCoverage middleware
 * into a single coverage report. Used after the Playwright e2e suite.
 */
class MergeCoverageCommand extends Command
{
    protected $signature = 'coverage:merge
        {--dir=storage/coverage : Directory holding the .cov dumps}
        {--html=coverage/e2e : Output directory for the HTML report}
        {--clover= : Optional clover.xml output path}
        {--keep : Keep the .cov dumps after merging (default: delete them)}';

    protected $description = 'Merge per-request coverage dumps into a single HTML/text report.';

    public function handle(): int
    {
        $dir = base_path($this->option('dir'));
        if (! is_dir($dir)) {
            $this->error("Coverage directory not found: {$dir}");

            return self::FAILURE;
        }

        $files = glob($dir.'/*.cov') ?: [];
        if ($files === []) {
            $this->warn("No .cov files in {$dir} — did the server boot with COVERAGE=1?");

            return self::FAILURE;
        }

        $this->info('Merging '.count($files).' coverage dumps...');

        $merged = null;
        foreach ($files as $i => $file) {
            try {
                // Two formats live in this directory:
                //  - Pest writes a PHP script (starts with `<?php return \unserialize(...)`)
                //    via its --coverage-php flag.
                //  - Our RecordCoverage middleware writes raw serialized data.
                // Detect by the leading bytes.
                $raw = file_get_contents($file);
                if (str_starts_with(ltrim($raw), '<?php')) {
                    $cov = include $file;
                } else {
                    $cov = @unserialize($raw, ['allowed_classes' => true]);
                }
            } catch (\Throwable $e) {
                $this->warn("Skipping unreadable dump {$file}: {$e->getMessage()}");

                continue;
            }
            if (! $cov instanceof CodeCoverage) {
                $this->warn("Skipping {$file}: not a CodeCoverage object");

                continue;
            }

            $merged ??= $cov;
            if ($merged !== $cov) {
                $merged->merge($cov);
            }

            if ($i > 0 && $i % 50 === 0) {
                $this->line("  merged {$i}/".count($files));
            }
        }

        if ($merged === null) {
            $this->error('No valid coverage dumps found.');

            return self::FAILURE;
        }

        // Text report — short percentage line to stdout.
        $text = (new TextReport(Thresholds::default(), false, true))->process($merged, showColors: false);
        $this->line($text);

        // HTML report.
        $htmlDir = base_path($this->option('html'));
        @mkdir($htmlDir, 0775, true);
        (new HtmlReport)->process($merged, $htmlDir);
        $this->info("HTML report: {$htmlDir}/index.html");

        if ($clover = $this->option('clover')) {
            (new CloverReport)->process($merged, base_path($clover));
            $this->info("Clover XML: {$clover}");
        }

        if (! $this->option('keep')) {
            foreach ($files as $f) {
                @unlink($f);
            }
            $this->line('Cleaned up '.count($files).' .cov dumps.');
        }

        return self::SUCCESS;
    }
}
