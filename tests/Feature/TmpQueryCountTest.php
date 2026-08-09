<?php

use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;

it('counts account_mapping queries during a demo seed', function () {
    $this->seed(\Database\Seeders\RolesPermissionsSeeder::class);

    $mappings = 0;
    $periods = 0;
    $total = 0;

    $callers = [];

    DB::listen(function ($q) use (&$mappings, &$periods, &$total, &$callers) {
        $total++;
        if (str_contains($q->sql, 'account_mappings')) {
            $mappings++;
            $frames = [];
            foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 40) as $f) {
                $file = $f['file'] ?? '';
                if (str_contains($file, '/app/') && ! str_contains($file, '/vendor/')) {
                    $frames[] = basename($file).':'.($f['line'] ?? '?');
                    if (count($frames) >= 3) {
                        break;
                    }
                }
            }
            $key = implode(' <- ', $frames);
            $callers[$key] = ($callers[$key] ?? 0) + 1;
        }
        if (str_contains($q->sql, 'accounting_periods')) {
            $periods++;
        }
    });

    $start = microtime(true);
    $this->seed(DemoSeeder::class);
    $elapsed = microtime(true) - $start;

    file_put_contents(
        getenv('QC_OUT') ?: '/tmp/qc.txt',
        sprintf("WALL %.2fs  TOTAL %d  account_mappings %d  accounting_periods %d\n\nTOP CALLERS:\n%s", $elapsed, $total, $mappings, $periods,
            implode("\n", array_map(fn ($n, $k) => sprintf('%5d  %s', $n, $k), $callers, array_keys($callers))))
    );

    expect($total)->toBeGreaterThan(0);
});
