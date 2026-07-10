<?php

use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The `--scheduled` flag keeps an automated full sweep best-effort: it must still
 * exit 0 even when individual documents fail to post, so a single legacy un-postable
 * doc can't turn the weekly cron perpetually red — while a human operator's `--all`
 * stays loud (non-zero) so real failures aren't missed. (GL integrity hardening — Phase 0.)
 *
 * We seed the chart but NOT the account mappings, so every invoice sync throws
 * "mapping missing" — a deterministic per-document failure.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class); // no AccountMappingSeeder → postings fail
    makeInvoice(makeLease(makeUnit(makeAsset())));
});

it('exits non-zero on a failed document for a human operator --all run', function () {
    $this->artisan('accounting:sync-ledger --all')->assertFailed();
});

it('stays best-effort (exit 0) for the scheduled --all backstop despite failures', function () {
    $this->artisan('accounting:sync-ledger --all --scheduled')->assertSuccessful();
});
