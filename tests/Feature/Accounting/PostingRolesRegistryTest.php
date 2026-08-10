<?php

use App\Models\AccountMapping;
use App\Support\PostingRoles;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * The posting-role registry and the seeded posting map must describe the same set of roles.
 *
 * `App\Support\PostingRoles` is what the operator picks from on the Posting Map screen. If a role is
 * added to the seeder and not the registry, it is unreachable — the screen cannot show or create it,
 * and the row can only be changed with SQL, which is the exact problem the screen was built to end.
 * In the other direction, a registry entry with no seeded default offers the operator a role the
 * ledger never asks for.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
});

it('offers exactly the roles the seeded posting map defines', function () {
    $seeded = AccountMapping::query()->whereNull('asset_id')->pluck('key')->sort()->values()->all();
    $registry = collect(PostingRoles::keys())->sort()->values()->all();

    expect(array_diff($seeded, $registry))->toBe([],
        'Seeded roles missing from App\Support\PostingRoles — unreachable on the Posting Map screen: '
        .implode(', ', array_diff($seeded, $registry)));

    expect(array_diff($registry, $seeded))->toBe([],
        'Registry roles with no seeded default — offered to the operator but never resolved: '
        .implode(', ', array_diff($registry, $seeded)));
});

it('gives every role a statement class and a label in both languages', function () {
    foreach (PostingRoles::keys() as $key) {
        expect(PostingRoles::group($key))->not->toBeNull("Role '{$key}' has no statement class");

        foreach (['en', 'ar'] as $locale) {
            app()->setLocale($locale);

            // A missing key returns the key path itself, which is how an untranslated label reaches
            // production looking like `admin.posting_roles.rent_revenue`.
            expect(PostingRoles::label($key))->not->toBe("admin.posting_roles.{$key}",
                "Role '{$key}' has no {$locale} label");
        }
    }

    app()->setLocale('en');
});

it('points every seeded role at the statement class the registry expects', function () {
    // Advisory in the UI, asserted here: the SEEDED chart is ours, so a mismatch in it is a wiring
    // mistake rather than a legitimate difference of opinion about someone else's chart.
    $mismatched = AccountMapping::query()
        ->whereNull('asset_id')
        ->with('account')
        ->get()
        ->filter(fn (AccountMapping $m) => $m->account?->type !== PostingRoles::group($m->key))
        ->map(fn (AccountMapping $m) => "{$m->key} → {$m->account?->code} ({$m->account?->type}, expected ".PostingRoles::group($m->key).')')
        ->all();

    expect($mismatched)->toBe([]);
});
