<?php

use App\Models\TenantRequest;
use Illuminate\Support\Facades\DB;

/**
 * Plan 2 (QA) — N+1 guard. A list endpoint must issue a (roughly) CONSTANT
 * number of queries regardless of how many rows it returns. We measure the
 * query count for a small page and a larger page (both under the page size, so
 * every row is serialised) and assert it does not grow per-row — the definitive
 * way to catch an N+1 regression in the resource/eager-loading.
 */
function queryCount(callable $fn): int
{
    DB::flushQueryLog();
    DB::enableQueryLog();
    $fn();
    $count = count(DB::getQueryLog());
    DB::disableQueryLog();

    return $count;
}

/**
 * @param  callable(int):array{0:mixed,1:string}  $build  returns [headers, url] for n rows
 */
function assertNoN1(callable $build): void
{
    // Warm caches (settings, permission lookups) so the first measured call
    // isn't inflated by one-time cold-cache queries.
    [$h, $u] = $build(1);
    test()->getJson($u, $h);

    [$few, $fewUrl] = $build(3);
    $small = queryCount(fn () => test()->getJson($fewUrl, $few)->assertOk());

    [$many, $manyUrl] = $build(6);
    $large = queryCount(fn () => test()->getJson($manyUrl, $many)->assertOk());

    // No per-row queries: doubling the rows must not add ~one query per extra row.
    expect($large)->toBeLessThanOrEqual($small + 1,
        "Query count grew from {$small} to {$large} as rows doubled — likely an N+1.");
}

it('serves /me/invoices without an N+1', function () {
    assertNoN1(function (int $n) {
        $tenant = makeTenant();
        $lease = makeLease(makeUnit(makeAsset()), $tenant);
        for ($i = 0; $i < $n; $i++) {
            makeInvoice($lease);
        }

        return [apiHeaders($tenant), '/api/v1/me/invoices'];
    });
});

it('serves /me/requests without an N+1', function () {
    assertNoN1(function (int $n) {
        $tenant = makeTenant();
        $unit = makeUnit(makeAsset());
        makeLease($unit, $tenant);
        for ($i = 0; $i < $n; $i++) {
            TenantRequest::create([
                'reference' => TenantRequest::generateReference(),
                'tenant_id' => $tenant->id,
                'unit_id' => $unit->id,
                'request_type' => 'maintenance',
                'status' => 'submitted',
                'priority' => 'medium',
                'category' => 'electrical',
                'title' => "Req {$i}",
                'description' => 'load test',
                'submitted_at' => now(),
            ]);
        }

        return [apiHeaders($tenant), '/api/v1/me/requests'];
    });
});
