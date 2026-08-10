<?php

use App\Enums\InvoiceItemType;
use App\Models\ChargeCode;
use App\Services\Accounting\Journalizers\InvoiceJournalizer;
use App\Support\PostingRoles;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChargeCodeSeeder;
use Database\Seeders\ChartOfAccountsSeeder;

/**
 * Every charge code must post somewhere on purpose.
 *
 * `InvoiceJournalizer::REVENUE_ROLE` maps an invoice item's type to a semantic posting role, and
 * falls back to `misc_income` for anything unmapped. The fallback is the hazard: a charge code added
 * without a line in that map does **not** fail. It books silently to miscellaneous income, so real
 * revenue is misclassified and nothing — no exception, no failing test, no unbalanced entry — says
 * so. The trial balance still ties out, because the money is there; it is simply in the wrong place.
 *
 * That is why `violation_fine` and `nsf_fee` carry explicit lines rather than relying on the
 * fallback that would have classified them correctly by accident. This gate makes that discipline
 * enforceable instead of a habit.
 */
it('maps every charge code to a posting role, or says why it is deliberately unmapped', function () {
    $mapped = array_keys(InvoiceJournalizer::REVENUE_ROLE);
    $byDesign = array_keys(InvoiceJournalizer::UNMAPPED_BY_DESIGN);

    $unaccounted = array_diff(InvoiceItemType::values(), $mapped, $byDesign);

    expect($unaccounted)->toBe([],
        'These charge codes would book silently to misc_income: '.implode(', ', $unaccounted)
        .'. Add a line to InvoiceJournalizer::REVENUE_ROLE, or to UNMAPPED_BY_DESIGN with the reason.');
});

it('never maps a charge code that is not a real type', function () {
    // The other direction: a renamed or removed type leaves a dead mapping behind, which reads as
    // coverage while mapping nothing.
    $orphans = array_diff(array_keys(InvoiceJournalizer::REVENUE_ROLE), InvoiceItemType::values());

    expect($orphans)->toBe([], 'Mapped codes that are not valid invoice item types: '.implode(', ', $orphans));
});

it('names only posting roles that exist in the registry', function () {
    // A typo'd role does not fail here — it fails at POSTING time with "No account mapping for role
    // …", long after the deploy, on a real tenant's invoice. Static check, red build instead.
    $unknown = collect(InvoiceJournalizer::REVENUE_ROLE)
        ->unique()
        ->reject(fn (string $role) => PostingRoles::group($role) !== null)
        ->values()
        ->all();

    expect($unknown)->toBe([], 'Roles not in App\Support\PostingRoles: '.implode(', ', $unknown));
});

it('resolves every mapped role to a real postable account', function () {
    // The end of the chain: registry → seeded mapping → chart account. Any break in it is a posting
    // that throws on a live invoice.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $resolver = app(App\Services\Accounting\AccountResolver::class);

    foreach (array_unique(array_values(InvoiceJournalizer::REVENUE_ROLE)) as $role) {
        $account = $resolver->account($role);

        expect($account->is_postable)->toBeTrue("Role '{$role}' resolves to a summary account");
        expect($account->is_active)->toBeTrue("Role '{$role}' resolves to an inactive account");
    }
});

it('gives every charge code in the catalogue the same account the hard-coded map gave it', function () {
    // THE requirement of this refactor. `charge_codes` replaced a private const map; if a single
    // code books somewhere new then revenue moved accounts on the day of a change that was supposed
    // to move nothing. Asserted code-for-code rather than by count.
    $this->seed(ChargeCodeSeeder::class);

    $catalogue = ChargeCode::query()->pluck('posting_role', 'code')->all();

    foreach (InvoiceJournalizer::REVENUE_ROLE as $code => $role) {
        // `toHaveKey($key, $value)` takes an expected VALUE as its second argument, not a message —
        // so the presence check and the value check are separate, with the message on the one that
        // can actually carry it.
        expect(array_key_exists($code, $catalogue))->toBeTrue("Charge code '{$code}' is missing from the catalogue");
        expect($catalogue[$code])->toBe($role,
            "Charge code '{$code}' books to '".($catalogue[$code] ?? 'null')."' in the catalogue but '{$role}' in the journalizer map");
    }
});

it('keeps a row for every code the billing engine has logic for', function () {
    // The enum survives as named references to the codes that carry BEHAVIOUR — cam_recovery and
    // percentage_rent are excluded from the monthly anti-double-bill probe, late_fee and nsf_fee
    // settle last. An operator deleting one of those rows would break that logic quietly, so the
    // catalogue must always cover the enum.
    $this->seed(ChargeCodeSeeder::class);

    $missing = array_diff(InvoiceItemType::values(), ChargeCode::query()->pluck('code')->all());

    expect($missing)->toBe([], 'Charge codes the engine references but the catalogue lacks: '.implode(', ', $missing));
});

it('names only posting roles the registry knows, in the catalogue too', function () {
    // Same trap as the map: a typo'd role in a row an accountant typed throws at POSTING time.
    $this->seed(ChargeCodeSeeder::class);

    $unknown = ChargeCode::query()
        ->whereNotNull('posting_role')
        ->pluck('posting_role')
        ->unique()
        ->reject(fn (string $role) => PostingRoles::group($role) !== null)
        ->values()
        ->all();

    expect($unknown)->toBe([], 'Catalogue roles not in App\Support\PostingRoles: '.implode(', ', $unknown));
});
