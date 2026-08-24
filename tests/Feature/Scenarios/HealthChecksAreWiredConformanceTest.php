<?php

/*
|--------------------------------------------------------------------------
| Every health check is actually reachable through /health
|--------------------------------------------------------------------------
| `Health` is a registry of ~20 checks and a `run()` that assembles them. Every check has its own
| tests — and almost all of them call the check METHOD directly, which says nothing about whether
| `run()` still lists it. Delete a line from `run()` and the check's own tests stay green while the
| endpoint silently stops reporting it: a row nobody runs is not a check.
|
| `php_extensions` shipped exactly like that. Five cases exercised `phpExtensionState()` and
| `missing()`, and `grep -rn php_extensions tests/` returned nothing — so the one assertion that
| would have caught a missing registration was the one nobody wrote. `backup_capability`'s test
| makes it, verbatim ("A check nobody runs is not a check"); five other rows did not.
|
| Pinned as a SET rather than per row, so adding a check forces a decision here and removing one
| cannot pass unnoticed in either direction.
*/

use App\Support\Health;

/**
 * Every key `Health::run()` is expected to report.
 *
 * Adding a check means adding it here. That is the point: the list is the contract `/health`
 * publishes, and both operations runbooks read specific keys out of it.
 */
const EXPECTED_HEALTH_CHECKS = [
    'accounting',
    'admin_access',
    'backup_capability',
    'backups',
    'books_tie_out',
    'browser_origin_policy',
    'cache',
    'database',
    'demo_accounts',
    'demo_payments',
    'mobile_reset_url',
    // Rotating the Paymob HMAC secret means accepting the OLD one for a few hours — Paymob signs
    // with whatever their dashboard holds, so callbacks in flight carry the previous signature.
    // This row fails in production once that window has closed and the secret is still in `.env`.
    'paymob_hmac_rotation',
    'php_extensions',
    'queue',
    'runtime_drivers',
    'scheduler',
    'storage',
    'translations',
    'two_factor',
    'withholding_tax',
];

it('reports every registered check, and only those', function () {
    $actual = array_keys(Health::run()['checks']);
    sort($actual);

    $expected = EXPECTED_HEALTH_CHECKS;
    sort($expected);

    expect($actual)->toBe($expected, implode("\n", [
        'The set of checks `Health::run()` reports has drifted from what this gate expects.',
        'A check missing from run() is silently absent from /health while its own unit tests stay green.',
        'A new check must be added to EXPECTED_HEALTH_CHECKS, so that adding one is a decision.',
    ]));
});

it('gives every check the shape the endpoint and the runbooks read', function () {
    foreach (Health::run()['checks'] as $key => $check) {
        expect($check)->toHaveKeys(['ok', 'detail'], "The '{$key}' row is missing ok/detail.")
            ->and($check['ok'])->toBeBool("The '{$key}' row's `ok` is not a boolean.");
    }
});
