<?php

use App\Models\PostDatedCheque;
use App\Services\PostDatedChequeService;

/**
 * The same physical cheque cannot be lodged twice.
 *
 * THE GAP (validation sweep — receivables, 2026-08-11). `cheque_number` had NO uniqueness at any
 * layer: no DB constraint, no model guard, not even a form rule. A cheque re-keyed by a second
 * operator — or a `lodgeSeries` run twice over the same cheque book, which produces the identical
 * sequential numbers by design — became two register rows for one piece of paper. Each is
 * independently clearable, and each clear records a captured Payment: the second settles AR that no
 * money backs, or (if the invoice is already settled) mints an on-account credit the tenant never
 * funded. The register is also the operator's forecast of cash to come, so a duplicate overstates
 * collections before anyone tries to clear it.
 *
 * THE KEY is (tenant, bank, cheque number) — a cheque number is unique within a bank account, and
 * two different tenants banking with different banks may legitimately hold the same number.
 * CANCELLED cheques are excluded so a mis-keyed entry can be cancelled and re-lodged correctly;
 * that carve-out is why this is a model guard rather than a unique index.
 *
 * DEVIATION FROM YARDI, stated deliberately: Yardi *warns* on a duplicate check number and lets the
 * operator proceed. We refuse. A PDC register that double-counts is a cash forecast that is wrong
 * in the operator's favour, and the cancel-and-re-lodge path costs one click.
 */
function lodgedCheque(array $attrs = []): PostDatedCheque
{
    $asset = $attrs['_asset'] ?? makeAsset();
    $tenant = $attrs['_tenant'] ?? makeTenant();
    unset($attrs['_asset'], $attrs['_tenant']);

    return PostDatedCheque::create(array_merge([
        'reference' => PostDatedCheque::generateReference(),
        'asset_id' => $asset->id,
        'tenant_id' => $tenant->id,
        'bank_name' => 'CIB',
        'cheque_number' => '100123',
        'amount' => 5000,
        'cheque_date' => '2026-09-01',
        'received_date' => '2026-08-01',
        'status' => PostDatedCheque::STATUS_HELD,
    ], $attrs));
}

it('refuses a second cheque with the same number from the same tenant and bank', function () {
    $asset = makeAsset();
    $tenant = makeTenant();

    lodgedCheque(['_asset' => $asset, '_tenant' => $tenant]);

    expect(fn () => lodgedCheque(['_asset' => $asset, '_tenant' => $tenant]))
        ->toThrow(DomainException::class);
});

it('allows the same cheque number for a different tenant', function () {
    $asset = makeAsset();

    lodgedCheque(['_asset' => $asset, '_tenant' => makeTenant()]);

    // The control: the guard is scoped, not a blanket ban — otherwise the refusal above
    // would pass for the wrong reason.
    expect(fn () => lodgedCheque(['_asset' => $asset, '_tenant' => makeTenant()]))
        ->not->toThrow(DomainException::class);
});

it('allows the same cheque number from a different bank', function () {
    $asset = makeAsset();
    $tenant = makeTenant();

    lodgedCheque(['_asset' => $asset, '_tenant' => $tenant, 'bank_name' => 'CIB']);

    expect(fn () => lodgedCheque(['_asset' => $asset, '_tenant' => $tenant, 'bank_name' => 'NBE']))
        ->not->toThrow(DomainException::class);
});

it('lets a CANCELLED cheque number be re-lodged (the mis-key correction path)', function () {
    $asset = makeAsset();
    $tenant = makeTenant();

    $wrong = lodgedCheque(['_asset' => $asset, '_tenant' => $tenant]);
    app(PostDatedChequeService::class)->cancel($wrong);

    expect(fn () => lodgedCheque(['_asset' => $asset, '_tenant' => $tenant]))
        ->not->toThrow(DomainException::class);
});

it('refuses EDITING a cheque onto a number another live cheque already holds', function () {
    $asset = makeAsset();
    $tenant = makeTenant();

    lodgedCheque(['_asset' => $asset, '_tenant' => $tenant, 'cheque_number' => '100123']);
    $second = lodgedCheque(['_asset' => $asset, '_tenant' => $tenant, 'cheque_number' => '100124']);

    expect(fn () => $second->update(['cheque_number' => '100123']))
        ->toThrow(DomainException::class);

    // Control: editing to a free number still works, so the refusal is the duplicate and
    // not a blanket immutability.
    expect(fn () => $second->update(['cheque_number' => '100125']))
        ->not->toThrow(DomainException::class);
});

it('refuses a lodged SERIES that would re-issue numbers already in the register', function () {
    $asset = makeAsset();
    $tenant = makeTenant();
    $svc = app(PostDatedChequeService::class);

    $series = [
        'asset_id' => $asset->id,
        'tenant_id' => $tenant->id,
        'bank_name' => 'CIB',
        'first_cheque_number' => '200500',
        'amount' => 5000,
        'count' => 6,
        'first_cheque_date' => '2026-09-01',
    ];

    expect($svc->lodgeSeries($series))->toHaveCount(6);

    // The same book lodged a second time — the sequential generator produces the identical
    // numbers by design, which is exactly how a year of cheques gets double-counted.
    expect(fn () => $svc->lodgeSeries($series))->toThrow(DomainException::class);

    // And the refusal is atomic: the failed run left no partial series behind.
    expect(PostDatedCheque::where('tenant_id', $tenant->id)->count())->toBe(6);
});
