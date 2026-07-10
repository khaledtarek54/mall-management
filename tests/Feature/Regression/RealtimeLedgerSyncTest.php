<?php

use App\Filament\Admin\Pages\BalanceSheet;
use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Jobs\SyncDocumentToLedger;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Accounting\LedgerReportService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * GL integrity hardening — Phase 2: near-real-time posting + freshness everywhere.
 * Documents post to the GL within seconds of a change (afterCommit queued job) instead
 * of waiting up to a day for the sweep, and every statement page can force + show freshness.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);
});

function realtimeInvoice(): Invoice
{
    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())), [
        'issue_date' => now()->toDateString(),
        'subtotal' => 10000, 'vat_amount' => 0, 'total' => 10000, 'balance' => 10000,
    ]);
    $invoice->items()->create(['type' => 'base_rent', 'description' => 'Rent', 'amount' => 10000, 'vat_rate' => 0, 'vat_amount' => 0, 'total' => 10000]);

    return $invoice;
}

// --- Part A: the near-real-time job ---

it('posts a document\'s journal entry when the sync job runs', function () {
    $invoice = realtimeInvoice();

    (new SyncDocumentToLedger(Invoice::class, $invoice->id))->handle(app(LedgerPoster::class));

    expect(JournalEntry::where('source_id', $invoice->id)->where('status', 'posted')->exists())->toBeTrue()
        ->and(app(LedgerReportService::class)->trialBalance()['balanced'])->toBeTrue();
});

it('voids the entry when the sync job runs for a soft-deleted document', function () {
    $invoice = realtimeInvoice();
    (new SyncDocumentToLedger(Invoice::class, $invoice->id))->handle(app(LedgerPoster::class));

    $invoice->delete();
    (new SyncDocumentToLedger(Invoice::class, $invoice->id))->handle(app(LedgerPoster::class));

    expect(JournalEntry::where('source_id', $invoice->id)->where('status', 'void')->count())->toBe(1);
});

it('no-ops safely when the source no longer exists', function () {
    (new SyncDocumentToLedger(Invoice::class, 999999))->handle(app(LedgerPoster::class));

    expect(JournalEntry::count())->toBe(0); // reached here without throwing
});

it('wires the saved/deleted/restored real-time dispatch on every posting source', function () {
    // Real-time is disabled in the test env (deterministic posting), so register explicitly
    // to prove the wiring attaches to every source LedgerPoster can journalize.
    \App\Support\LedgerRealtimeSync::register();

    foreach (\App\Support\LedgerRealtimeSync::SOURCES as $model) {
        expect($model::getEventDispatcher()->hasListeners('eloquent.saved: '.$model))->toBeTrue()
            ->and($model::getEventDispatcher()->hasListeners('eloquent.deleted: '.$model))->toBeTrue()
            ->and($model::getEventDispatcher()->hasListeners('eloquent.restored: '.$model))->toBeTrue();
    }
});

it('dispatches the real-time sync job when a posting source is saved (enabled)', function () {
    config(['accounting.realtime_ledger_sync' => true]);
    \App\Support\LedgerRealtimeSync::register();
    \Illuminate\Support\Facades\Bus::fake(); // capture the dispatch without running it

    $invoice = makeInvoice(makeLease(makeUnit(makeAsset())));

    \Illuminate\Support\Facades\Bus::assertDispatched(
        SyncDocumentToLedger::class,
        fn ($job) => $job->sourceType === Invoice::class && (int) $job->sourceId === $invoice->id,
    );
});

// --- Part B: freshness button + indicator on all statement pages ---

it('exposes the post-to-ledger action + last-synced subheading on every statement page', function () {
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant(makeAsset());

    foreach ([IncomeStatement::class, BalanceSheet::class, GeneralLedger::class] as $page) {
        Livewire::test($page)
            ->assertOk()
            ->assertActionVisible('post_to_ledger')
            ->assertSee('not yet synced'); // the never-synced subheading copy
    }
});
