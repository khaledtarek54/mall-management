<?php

/*
|--------------------------------------------------------------------------
| One command takes a migrated database to one that can bill AND post
|--------------------------------------------------------------------------
| The reference data this system cannot work without ships as SEEDERS: roles and their permission
| catalogue, the chart of accounts, the account mappings, the charge codes, an open fiscal year. A
| database with only migrations bills perfectly and posts NOTHING — see
| FreshInstallGoLiveReadinessTest for that state reproduced.
|
| The runbook's answer was three commands in a list, which is a thing someone half-follows. This is
| the command that runs them in order and then VERIFIES the result, so "installed" means "proved it
| can post" rather than "the seeders exited 0".
|
| The assertion that matters is the last one: after `atriom:install`, the same walkthrough that
| produced an empty ledger produces journal entries.
*/

use App\Models\Charge;
use App\Models\ChargeCode;
use App\Models\JournalEntry;
use App\Models\LedgerAccount;
use App\Models\User;
use App\Services\MonthlyBillingService;
use App\Support\Health;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/** Bill one month on a lease — the same walkthrough, run before and after installing. */
function billOneMonth(): array
{
    $asset = makeAsset(['code' => 'INST']);
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

it('takes a migrated database to one that actually posts', function () {
    // Before: the state a production box is in after `migrate`.
    expect(LedgerAccount::count())->toBe(0)
        ->and(Health::accountingReadiness()['ok'])->toBeFalse();

    $this->artisan('atriom:install --force')->assertSuccessful();

    expect(Health::accountingReadiness()['ok'])->toBeTrue()
        ->and(Role::count())->toBeGreaterThan(0)
        ->and(Permission::count())->toBeGreaterThan(0)
        ->and(ChargeCode::count())->toBeGreaterThan(0);

    // The payoff, and the only assertion that proves the install rather than the seeders: the
    // walkthrough that left the ledger empty now reaches the books.
    $result = billOneMonth();
    $this->artisan('accounting:sync-ledger', ['--all' => true])->assertSuccessful();

    expect($result['status'])->toBe('created')
        ->and(JournalEntry::count())->toBeGreaterThan(0);
});

it('is safe to run twice on a live system', function () {
    $this->artisan('atriom:install --force')->assertSuccessful();

    // A business row, to prove the second run re-asserts reference data without touching data.
    $result = billOneMonth();
    $invoice = $result['invoice'];

    $before = [
        'accounts' => LedgerAccount::count(),
        'mappings' => DB::table('account_mappings')->count(),
        'codes' => ChargeCode::count(),
        'roles' => Role::count(),
    ];

    $this->artisan('atriom:install --force')->assertSuccessful();

    expect([
        'accounts' => LedgerAccount::count(),
        'mappings' => DB::table('account_mappings')->count(),
        'codes' => ChargeCode::count(),
        'roles' => Role::count(),
    ])->toBe($before)
        ->and($invoice->fresh()->total)->not->toBeNull()
        ->and((float) $invoice->fresh()->total)->toBe(30000.0);
});

it('creates the first administrator, because nothing else ever has', function () {
    // DemoSeeder is the only thing in the codebase that creates a User — and a production box must
    // not run it. So the documented deploy used to end with an empty users table and no way in.
    expect(User::count())->toBe(0);

    $this->artisan('atriom:install --force --admin-email=ops@eltizam.example --admin-name="Ops Lead"')
        ->assertSuccessful();

    $admin = User::where('email', 'ops@eltizam.example')->sole();

    expect($admin->hasRole('super_admin'))->toBeTrue()
        ->and($admin->name)->toBe('Ops Lead')
        // A generated password, not a known one — the demo-login problem is what this avoids.
        ->and(Hash::check('password', $admin->password))->toBeFalse();
});

it('uses the password it was given, and leaves an existing administrator alone', function () {
    $this->artisan('atriom:install --force --admin-email=first@eltizam.example --admin-password=correct-horse-battery')
        ->assertSuccessful();

    expect(Hash::check('correct-horse-battery', User::where('email', 'first@eltizam.example')->sole()->password))
        ->toBeTrue();

    // A second run must not mint a second administrator, or every deploy grows the list of
    // accounts that can do anything.
    $this->artisan('atriom:install --force --admin-email=second@eltizam.example')->assertSuccessful();

    expect(User::role('super_admin')->count())->toBe(1)
        ->and(User::where('email', 'second@eltizam.example')->exists())->toBeFalse();
});

it('reports an install nobody can sign in to as unhealthy in production', function () {
    $this->artisan('atriom:install --force')->assertSuccessful();

    inEnvironment('production');
    $check = Health::run()['checks']['admin_access'];

    expect($check['ok'])->toBeFalse()
        ->and($check['detail'])->toContain('super_admin');

    // The control: create one and the same check passes.
    $this->artisan('atriom:install --force --admin-email=ops@eltizam.example')->assertSuccessful();

    expect(Health::run()['checks']['admin_access']['ok'])->toBeTrue();
});

it('warns about the published demo logins it did not create', function () {
    User::factory()->create(['email' => 'admin@mall.test']);

    $this->artisan('atriom:install --force')
        ->expectsOutputToContain('admin@mall.test')
        ->assertSuccessful();
});
