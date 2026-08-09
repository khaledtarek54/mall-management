<?php
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\LedgerPoster;
it('shares one resolver', function () {
    $a = app(AccountResolver::class); $b = app(AccountResolver::class);
    $p1 = app(LedgerPoster::class); $p2 = app(LedgerPoster::class);
    $r = new ReflectionProperty(LedgerPoster::class, 'accounts'); $r->setAccessible(true);
    dump([
      'resolver shared' => $a === $b,
      'poster resolver shared' => $r->getValue($p1) === $r->getValue($p2),
    ]);
    expect(true)->toBeTrue();
});
