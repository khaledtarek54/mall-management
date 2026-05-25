<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use SebastianBergmann\CodeCoverage\CodeCoverage;
use SebastianBergmann\CodeCoverage\Driver\Selector;
use SebastianBergmann\CodeCoverage\Filter;

/**
 * Captures PHP line coverage for each HTTP request and writes a .cov file to
 * storage/coverage/. Only activated when the COVERAGE env var is set, so it
 * has zero impact in dev/prod. Used by the Playwright e2e runner to produce
 * a single merged coverage report across the whole browser-driven suite.
 *
 * One .cov file per request keeps writes cheap and parallel-safe. The
 * coverage:merge artisan command stitches them into the final report.
 */
class RecordCoverage
{
    public function handle(Request $request, Closure $next)
    {
        @file_put_contents('/tmp/cov-debug.log', date('H:i:s') . " handle() path={$request->path()} shouldRecord=" . (self::shouldRecord() ? 'yes' : 'no') . "\n", FILE_APPEND);

        if (! self::shouldRecord()) {
            return $next($request);
        }

        $coverage = $this->makeCoverage();
        $coverage->start($request->path() ?: 'root');

        $response = $next($request);

        $coverage->stop();
        $this->persist($coverage);

        return $response;
    }

    public static function shouldRecord(): bool
    {
        // Read straight from PHP's env layer instead of Laravel's env() helper —
        // env() returns null once config is cached, but the serve command never
        // boots through that path. getenv()/$_SERVER are always populated.
        return (bool) (getenv('COVERAGE') ?: ($_SERVER['COVERAGE'] ?? null));
    }

    private function makeCoverage(): CodeCoverage
    {
        $filter = new Filter;
        $filter->includeDirectory(base_path('app'));

        return new CodeCoverage(
            (new Selector)->forLineCoverage($filter),
            $filter,
        );
    }

    private function persist(CodeCoverage $coverage): void
    {
        $dir = storage_path('coverage');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $file = $dir . '/req-' . uniqid('', true) . '.cov';
        file_put_contents($file, serialize($coverage));
    }
}
