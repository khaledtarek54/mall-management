<?php

/*
|--------------------------------------------------------------------------
| A fresh install bills perfectly and posts nothing — and now says so
|--------------------------------------------------------------------------
| Reproduced on an empty database (migrations only, which is what a production deploy has the
| morning after `migrate`): create a property, a unit, a tenant and a lease, run the monthly
| billing, and you get a correct EGP 30,000 invoice — while `accounting:sync-ledger` refuses every
| posting with "No account mapping for role 'accounts_receivable'" and the general ledger sits at
| ZERO entries. The chart of accounts is a SEEDER, not a migration.
|
| Nothing in the running application said so. The realtime posting hook is best-effort by design,
| and the sweep's non-zero exit goes to a cron log nobody reads — so the first person to notice
| would have been the accountant asking for a trial balance, a month of invoices later.
|
| The two checks added here turn that into an answer the system gives: `accounting` resolves every
| posting role through the real AccountResolver, and `demo_accounts` reports the seeded logins whose
| password is published in DEMO.md. Both are production-gated, like `two_factor` and
| `backup_capability` before them — a developer between `migrate` and `db:seed` is not broken.
*/

use App\Models\Charge;
use App\Models\JournalEntry;
use App\Models\User;
use App\Services\MonthlyBillingService;
use App\Support\Health;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\Hash;

/** The state a production database is in after `migrate` and before any seeding. */
function freshInstallInvoice(): array
{
    $asset = makeAsset(['code' => 'FRESH']);
    $lease = makeLease(makeUnit($asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-03-01',
        'expiry_date' => '2027-02-28',
        'base_rent_monthly' => 30000,
    ]);

    Charge::create([
        'lease_id' => $lease->id, 'name' => 'Base Rent', 'type' => 'base_rent',
        'amount' => 30000, 'currency' => 'EGP', 'frequency' => 'monthly',
        'vat_applicable' => false, 'vat_rate' => 0,
        'start_date' => '2026-03-01', 'is_active' => true,
    ]);

    $result = app(MonthlyBillingService::class)
        ->generateForLease($lease->fresh(), CarbonImmutable::parse('2026-03-01'));
    $result['invoice']?->update(['status' => 'issued']);

    return $result;
}

it('bills correctly and posts nothing when the chart of accounts was never seeded', function () {
    $result = freshInstallInvoice();

    // The half that works, which is exactly why nobody notices the half that does not.
    expect($result['status'])->toBe('created')
        ->and((float) $result['invoice']->total)->toBe(30000.0);

    // The sweep refuses every posting — and says so only to its own exit code.
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertFailed();

    expect(JournalEntry::count())->toBe(0, 'an unseeded chart cannot post, which is the point');
});

it('reports the unpostable install as unhealthy in production', function () {
    freshInstallInvoice();

    inEnvironment('production');
    $check = Health::run()['checks']['accounting'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('accounts_receivable')
        // The remedy, named — a health check that only says "broken" makes someone go looking.
        ->and($check['detail'])->toContain('atriom:install');

    // The control: seed the chart + mappings and the same check passes, so the failure above is
    // the missing chart and not the check reporting red on everything.
    //
    // Run the seeders directly rather than through `$this->seed()`: `db:seed` is confirmable, and
    // `ConfirmableTrait` reads `app()->environment()` — so in a genuine production environment it
    // asks before running and aborts unanswered. That is correct of Laravel, and it only surfaces
    // now because this test used to set `config('app.env')` alone, leaving the framework itself
    // still in `testing` while `Health` was told it was live.
    app(ChartOfAccountsSeeder::class)->run();
    app(AccountMappingSeeder::class)->run();

    expect(Health::run()['checks']['accounting']['ok'])->toBeTrue();
});

it('does not cry wolf on a developer machine', function () {
    // Local is the state between `migrate` and `db:seed`, which is not a broken install.
    inEnvironment('local');

    expect(Health::run()['checks']['accounting']['ok'])->toBeTrue()
        ->and(Health::run()['checks']['accounting']['detail'])->toContain('not enforced');
});

it('reports the seeded demo logins as unhealthy in production', function () {
    // The go-live checklist has said "rotate the seeded demo password" for weeks. These accounts
    // include a super_admin, and their password is published.
    User::factory()->create(['email' => 'admin@mall.test']);
    User::factory()->create(['email' => 'owner@atriom.test']);

    inEnvironment('production');
    $check = Health::run()['checks']['demo_accounts'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('admin@mall.test');

    // The control: an install with only real accounts passes.
    User::query()->whereIn('email', ['admin@mall.test', 'owner@atriom.test'])->forceDelete();
    User::factory()->create(['email' => 'ops@eltizam.example']);

    expect(Health::run()['checks']['demo_accounts']['ok'])->toBeTrue();
});

it('flags a demo account whose password was rotated', function () {
    // Rotating `DEMO_USER_PASSWORD` changes the secret, not the fact that the account belongs to
    // nobody and sits on a role nobody audits — so the check matches the account, not the hash.
    User::factory()->create([
        'email' => 'accounting@mall.test',
        'password' => Hash::make('a-long-rotated-secret'),
    ]);

    inEnvironment('production');

    expect(Health::run()['checks']['demo_accounts']['ok'])->toBeFalse();
});
