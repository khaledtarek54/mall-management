<?php

use App\Filament\Admin\Pages\TrialBalance;
use App\Models\SystemSetting;
use App\Notifications\LedgerSyncFailedNotification;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;

/**
 * GL integrity hardening — Phase 3: an un-postable document (the closed-period reversal
 * trap et al.) must never be silent. The sweep alerts the GL managers + stamps a failure
 * count the report pages surface, de-duplicated so a persistent failure alerts once.
 *
 * Seeding the chart but NOT the account mappings makes every invoice sync throw
 * "mapping missing" — a deterministic per-document failure.
 */
it('alerts the GL managers when a document cannot be posted', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class); // no mappings → sync fails
    $admin = makeUser('super_admin');
    makeInvoice(makeLease(makeUnit(makeAsset())));

    Notification::fake();
    $this->artisan('accounting:sync-ledger --all')->assertFailed();

    Notification::assertSentTo($admin, LedgerSyncFailedNotification::class);
    expect((int) SystemSetting::get('ledger_last_sync_failures'))->toBeGreaterThan(0);
});

it('does not re-alert when the failure count is unchanged (dedupe)', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    makeUser('super_admin');
    makeInvoice(makeLease(makeUnit(makeAsset())));

    $this->artisan('accounting:sync-ledger --all'); // first run stamps + alerts (for real)

    Notification::fake();
    $this->artisan('accounting:sync-ledger --all'); // same failure count → no new alert

    Notification::assertNothingSent();
});

it('surfaces the sync-failure warning on a report page freshness subheading', function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    SystemSetting::put('ledger_last_synced_at', now()->toIso8601String());
    SystemSetting::put('ledger_last_sync_failures', '2');

    Livewire::test(TrialBalance::class)
        ->assertOk()
        ->assertSee('could not be posted'); // the warning copy
});

it('a windowed run does not clear a warning for an out-of-window failure', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    SystemSetting::put('ledger_last_sync_failures', '1'); // a prior --all detected an old stranded doc

    // The daily windowed run visits nothing recent — it must NOT clear a warning it had
    // no scope to re-verify (else the old closed-period-trap doc's warning vanishes).
    $this->artisan('accounting:sync-ledger');

    expect((int) SystemSetting::get('ledger_last_sync_failures'))->toBe(1);
});

it('a full --all run clears the warning when everything posts', function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    SystemSetting::put('ledger_last_sync_failures', '3'); // stale prior failure

    $this->artisan('accounting:sync-ledger --all'); // full scope, nothing fails

    expect((int) SystemSetting::get('ledger_last_sync_failures'))->toBe(0);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));
