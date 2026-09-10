<?php

use App\Support\Health;

/**
 * Regression — OPS-09. `atriom:health` says whether Redis has a memory cap, keeps `noeviction`, and
 * is not near the cap.
 *
 * Measured on the staging box, 2026-09-10: `maxmemory 0` — no cap — under `noeviction`. The
 * policy is the right one (INFRASTRUCTURE.md §5: this store holds every `Cache::lock()`, and any
 * LRU policy can evict a lock key mid-run); the missing cap is the wrong half, because a runaway
 * cache is then ended by the OS OOM-killer taking sessions, the queue and the locks, where a cap
 * under `noeviction` ends it with a refused write that reaches this row. The backlog row proposed
 * `allkeys-lru` plus a second instance for the queue — which would have moved the queue and left
 * the locks evictable — so the decision is recorded here in the direction §5 already argues.
 *
 * The verdict is a pure function of three facts, driven here without a Redis; the fact-reading
 * half is exercised only on a deployed box.
 */
it('is red with no cap — the OOM-killer is not a failure mode a health row can read', function () {
    $verdict = Health::redisMemoryVerdict(['maxmemory' => 0, 'policy' => 'noeviction', 'used' => 1_740_000], 'staging');

    expect($verdict['ok'])->toBeFalse()
        ->and($verdict['detail'])->toContain('NO maxmemory cap')
        ->and($verdict['detail'])->toContain('noeviction');
});

it('is red on any eviction policy — a lock key must never be evictable', function () {
    foreach (['allkeys-lru', 'volatile-lru', 'volatile-ttl'] as $policy) {
        $verdict = Health::redisMemoryVerdict(['maxmemory' => 268_435_456, 'policy' => $policy, 'used' => 1_740_000], 'production');

        expect($verdict['ok'])->toBeFalse()
            ->and($verdict['detail'])->toContain("`{$policy}`")
            ->and($verdict['detail'])->toContain('Cache::lock()');
    }
});

it('is red at 80% of the cap, before the refusals start', function () {
    $cap = 268_435_456;

    $verdict = Health::redisMemoryVerdict(['maxmemory' => $cap, 'policy' => 'noeviction', 'used' => (int) ($cap * 0.8)], 'production');

    expect($verdict['ok'])->toBeFalse()
        ->and($verdict['detail'])->toContain('80%')
        ->and($verdict['detail'])->toContain('REFUSING writes');
});

it('is green with a cap, noeviction and headroom — the control, with the figures a person reads', function () {
    $verdict = Health::redisMemoryVerdict(['maxmemory' => 268_435_456, 'policy' => 'noeviction', 'used' => 1_740_000], 'production');

    expect($verdict['ok'])->toBeTrue()
        ->and($verdict['detail'])->toBe('1.7 MB of 256.0 MB (1%), noeviction');
});

it('is registered in the health run', function () {
    expect(Health::run()['checks'])->toHaveKey('redis_memory');
});
