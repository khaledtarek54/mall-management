<?php

use App\Models\PostDatedCheque;
use App\Services\PostDatedChequeService;
use Carbon\CarbonImmutable;

/**
 * Bulk series lodging (module 33). In Egypt a tenant lodges a year of monthly post-dated cheques up
 * front; entering them one at a time is slow and error-prone. `lodgeSeries()` creates the whole
 * series in one act — sequential numbers, maturities one interval apart. These pin that, plus the
 * shared maturity scopes the scan / card / filter all read.
 */
function seriesData(array $overrides = []): array
{
    return array_merge([
        'asset_id' => makeAsset()->id,
        'tenant_id' => makeTenant()->id,
        'bank_name' => 'CIB',
        'first_cheque_number' => '100500',
        'amount' => 25000,
        'count' => 12,
        'first_cheque_date' => '2026-08-01',
        'received_date' => '2026-07-20',
        'interval_months' => 1,
    ], $overrides);
}

it('lodges a 12-cheque monthly series with sequential numbers and monthly maturities', function () {
    $created = app(PostDatedChequeService::class)->lodgeSeries(seriesData());

    expect($created)->toHaveCount(12)
        ->and(PostDatedCheque::count())->toBe(12);

    $ordered = PostDatedCheque::orderBy('cheque_date')->get();

    // Sequential numbers off the first, zero-pad width preserved.
    expect($ordered->first()->cheque_number)->toBe('100500')
        ->and($ordered->get(1)->cheque_number)->toBe('100501')
        ->and($ordered->last()->cheque_number)->toBe('100511')
        // Maturities march one month at a time from the first.
        ->and($ordered->first()->cheque_date->toDateString())->toBe('2026-08-01')
        ->and($ordered->get(1)->cheque_date->toDateString())->toBe('2026-09-01')
        ->and($ordered->last()->cheque_date->toDateString())->toBe('2027-07-01')
        // Every cheque is held, same amount, distinct reference.
        ->and($ordered->pluck('status')->unique()->all())->toBe([PostDatedCheque::STATUS_HELD])
        ->and((float) $ordered->first()->amount)->toBe(25000.0)
        ->and($ordered->pluck('reference')->unique()->count())->toBe(12);
});

it('honours a quarterly interval', function () {
    $created = app(PostDatedChequeService::class)->lodgeSeries(seriesData(['count' => 4, 'interval_months' => 3]));

    $dates = $created->sortBy('cheque_date')->pluck('cheque_date')->map->toDateString()->values()->all();

    expect($dates)->toBe(['2026-08-01', '2026-11-01', '2027-02-01', '2027-05-01']);
});

it('handles a non-numeric cheque number without colliding', function () {
    $created = app(PostDatedChequeService::class)->lodgeSeries(seriesData(['first_cheque_number' => 'ABC', 'count' => 3]));

    $numbers = $created->pluck('cheque_number')->all();

    expect($numbers)->toBe(['ABC', 'ABC-2', 'ABC-3'])
        ->and(collect($numbers)->unique()->count())->toBe(3);
});

it('rejects an out-of-range count or a zero amount', function () {
    $svc = app(PostDatedChequeService::class);

    expect(fn () => $svc->lodgeSeries(seriesData(['count' => 0])))->toThrow(DomainException::class)
        ->and(fn () => $svc->lodgeSeries(seriesData(['count' => 61])))->toThrow(DomainException::class)
        ->and(fn () => $svc->lodgeSeries(seriesData(['amount' => 0])))->toThrow(DomainException::class);

    expect(PostDatedCheque::count())->toBe(0); // nothing partially created
});

it('shares the matured-uncleared scope across scan, card and filter', function () {
    $asset = makeAsset();
    $tenant = makeTenant();
    // Two matured (held/deposited, cheque_date in the past), one future, one already cleared.
    PostDatedCheque::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'reference' => 'PDC-T-A1', 'cheque_number' => 'A1', 'amount' => 100, 'cheque_date' => '2026-06-01', 'received_date' => '2026-05-01', 'status' => 'held']);
    PostDatedCheque::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'reference' => 'PDC-T-A2', 'cheque_number' => 'A2', 'amount' => 100, 'cheque_date' => '2026-06-15', 'received_date' => '2026-05-01', 'status' => 'deposited']);
    PostDatedCheque::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'reference' => 'PDC-T-A3', 'cheque_number' => 'A3', 'amount' => 100, 'cheque_date' => '2027-01-01', 'received_date' => '2026-05-01', 'status' => 'held']);
    PostDatedCheque::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id, 'reference' => 'PDC-T-A4', 'cheque_number' => 'A4', 'amount' => 100, 'cheque_date' => '2026-06-01', 'received_date' => '2026-05-01', 'status' => 'cleared']);

    $on = CarbonImmutable::create(2026, 7, 1);

    expect(PostDatedCheque::query()->maturedUncleared($on)->count())->toBe(2)          // A1 + A2
        ->and(PostDatedCheque::query()->maturingWithin(200, $on)->count())->toBe(1);   // A3 within ~200d
});
