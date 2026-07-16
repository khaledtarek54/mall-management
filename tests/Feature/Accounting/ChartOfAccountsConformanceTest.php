<?php

use App\Models\AccountMapping;
use App\Models\LedgerAccount;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Conformance gate on the starter chart (دليل الحسابات).
 *
 * ChartOfAccountsSeeder is a flat list whose tree, type and normal balance are all
 * DERIVED — the parent from the code's longest existing prefix, the type from its leading
 * digit. That is convenient to maintain and easy to break by hand: a code typo silently
 * re-parents an account (or orphans it), and AccountMappingSeeder silently SKIPS a role
 * whose account is missing, turning a typo into a posting recipe that fails at runtime
 * rather than at seed time. These assertions turn all of that into a red test.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
});

it('parents every non-root account to a real prefix of its own code', function () {
    $codeById = LedgerAccount::pluck('code', 'id');

    LedgerAccount::all()->each(function (LedgerAccount $a) use ($codeById) {
        if (strlen($a->code) === 1) {
            expect($a->parent_id)->toBeNull("Root account {$a->code} should have no parent");

            return;
        }

        expect($a->parent_id)->not->toBeNull("Account {$a->code} is orphaned — no ancestor exists");

        $parentCode = $codeById[$a->parent_id];
        expect(str_starts_with($a->code, (string) $parentCode))->toBeTrue(
            "Account {$a->code} is parented to {$parentCode}, which is not a prefix of it"
        );
    });
});

it('types every account to match its leading code digit', function () {
    LedgerAccount::all()->each(function (LedgerAccount $a) {
        $expected = LedgerAccount::expectedTypeForCode((string) $a->code);

        if ($expected !== null) {
            expect($a->type)->toBe($expected, "Account {$a->code} is typed '{$a->type}', expected '{$expected}'");
        }

        expect($a->normal_balance)->toBe(
            LedgerAccount::normalBalanceFor($a->type),
            "Account {$a->code} has a normal_balance out of step with its type"
        );
    });
});

it('never marks a summary account postable', function () {
    LedgerAccount::with('children')->get()
        ->filter(fn (LedgerAccount $a) => $a->children->isNotEmpty())
        ->each(fn (LedgerAccount $a) => expect($a->is_postable)->toBeFalse(
            "Summary account {$a->code} has children and must not accept journal lines"
        ));
});

it('is idempotent — reseeding neither duplicates nor re-parents', function () {
    $before = LedgerAccount::orderBy('code')->pluck('parent_id', 'code');

    $this->seed(ChartOfAccountsSeeder::class);

    expect(LedgerAccount::orderBy('code')->pluck('parent_id', 'code')->all())->toBe($before->all());
});

it('seeds every default mapping onto a postable, active account', function () {
    $this->seed(AccountMappingSeeder::class);

    // Reflect the seeder's own role list, so a role added there without a matching chart
    // account fails here rather than silently vanishing into the `continue`.
    $roles = (new ReflectionClass(AccountMappingSeeder::class))->getConstant('MAP');

    $seeded = AccountMapping::with('account')->get()->keyBy('key');

    foreach ($roles as $role => $code) {
        expect($seeded->has($role))->toBeTrue("Mapping role '{$role}' was skipped — chart account {$code} is missing");
        expect($seeded[$role]->account->code)->toBe($code);
        expect($seeded[$role]->account->is_postable)->toBeTrue("Role '{$role}' points at summary account {$code}");
        expect($seeded[$role]->account->is_active)->toBeTrue("Role '{$role}' points at inactive account {$code}");
    }
});
