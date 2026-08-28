<?php

/**
 * **The ledger panel is on the screen where the edit happens, and the save says the books moved.**
 *
 * CHANGE-IMPACT-PLAN §6.1 built `LedgerEntryAction` and mounted it on five LIST tables. A list is
 * where you audit; the Edit page is where you act, and an operator about to retype a figure could
 * not see what the document had already done to the books without leaving the page. §9 F3 separately
 * left "a re-derive is silent" open for the person who caused it.
 *
 * **Mounting is the assertion, not construction.** CLAUDE.md: *"Build an action in a test and you
 * have tested nothing; `mount()` is the seam Filament calls on open."* The panel's schema closure is
 * typed `fn (Model $record)`, and whether Filament injects the record differs between a table row
 * action and a PAGE HEADER action — which is exactly the kind of thing that reads as correct and
 * throws the first time an operator clicks it.
 */

use App\Filament\Admin\Resources\Expenses\Pages\EditExpense;
use App\Filament\Admin\Resources\MarketingBudgets\Pages\EditMarketingBudget;
use App\Filament\Admin\Resources\MarketingBudgets\RelationManagers\MarketingSpendsRelationManager;
use App\Models\Expense;
use App\Models\JournalEntry;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    ensureAllPropertiesAsset();
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $this->asset = makeAsset();
    $this->user = makeUser('super_admin');
    $this->actingAs($this->user);
    Filament::setTenant($this->asset);

    $this->expense = Expense::create([
        'asset_id' => $this->asset->id,
        'category' => 'utilities',
        'description' => 'Generator diesel',
        'amount' => 1000, 'vat_amount' => 0,
        'paid_from' => 'cash',
        'expense_date' => now()->toDateString(),
        'status' => 'recorded',
    ]);
});

it('opens the ledger panel from the document itself', function () {
    // mountAction, not a constructed Action: the schema closure takes `Model $record`, and whether
    // Filament injects it on a PAGE HEADER (rather than a table row) is the thing being proved.
    Livewire::test(EditExpense::class, ['record' => $this->expense->id])
        ->mountAction('ledgerEntry')
        ->assertHasNoActionErrors()
        ->assertSuccessful();
});

it('tells the operator when the save will restate the ledger', function () {
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    // `expense_date` is DERIVED — it moves the entry into another period — so after this save the
    // posted entry is out of step and the toast must say so. Asserted on the notification the page
    // actually builds, because the title is Filament's own and only the BODY is ours.
    $page = Livewire::test(EditExpense::class, ['record' => $this->expense->id])
        ->fillForm(['expense_date' => now()->subMonth()->toDateString()])
        ->call('save')
        ->assertHasNoFormErrors()
        ->instance();

    // A DATE-only correction: the figure does not move, the PERIOD does — and the toast has to say
    // that rather than "reversed EGP 1,000 and re-posted at EGP 1,000", which reads as a no-op and
    // hides the only thing that changed.
    expect(invade($page)->getSavedNotification()?->getBody())
        ->toBe(__('admin.notifications.ledger_will_move_month', [
            'amount' => 'EGP 1,000.00',
            'month' => now()->subMonth()->format('m/Y'),
        ]));
});

it('says nothing extra when the save leaves the ledger alone', function () {
    // The control, and the half that matters: a body added to every save would be noise nobody
    // reads, which is precisely why §9 F3 declined a notification per re-derive.
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $poster = app(LedgerPoster::class);

    expect($poster->wouldChange($this->expense->fresh()))->toBeFalse();

    $this->expense->description = 'Generator diesel — August';
    $this->expense->save();

    expect($poster->wouldChange($this->expense->fresh()))->toBeFalse();
});

// ───────────── The new reversal buttons actually open and actually reverse ─────────────

/**
 * `ReverseDocumentAction` is a factory used on four screens, and its schema and action closures are
 * only ever evaluated when an operator OPENS the modal. CLAUDE.md: *"Build an action in a test and
 * you have tested nothing; `mount()` is the seam Filament calls on open."* Both halves are driven
 * here — the modal opens, and the act does what the button says.
 */
it('opens the reverse modal on a marketing spend and reverses it with a reason', function () {
    $budget = MarketingBudget::create([
        'asset_id' => $this->asset->id,
        'period_year' => (int) now()->year,
        'accrued_amount' => 500000,
    ]);
    $spend = MarketingSpend::create([
        'marketing_budget_id' => $budget->id,
        'category' => 'event',
        'description' => 'Ramadan activation',
        'amount' => 1590,
        'paid_from' => 'bank',
        'spent_on' => now()->toDateString(),
    ]);

    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    expect(JournalEntry::where('source_type', $spend->getMorphClass())
        ->where('source_id', $spend->id)->where('status', 'posted')->exists())->toBeTrue();

    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => EditMarketingBudget::class,
    ])
        ->mountTableAction('reverse_document', $spend)
        ->setTableActionData(['reason' => 'booked to the wrong budget'])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    // The document is reversed, and the WHY is in the trail rather than in an editable column.
    expect($spend->fresh()->trashed())->toBeTrue()
        ->and(Activity::where('subject_type', $spend->getMorphClass())
            ->where('subject_id', $spend->id)
            ->where('event', 'cancelled')
            ->value('properties')['reason'] ?? null)->toBe('booked to the wrong budget');

    // …and the ledger entry follows, through the REAL sweep.
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    expect(JournalEntry::where('source_type', $spend->getMorphClass())
        ->where('source_id', $spend->id)->where('status', 'posted')->exists())->toBeFalse();
});

it('refuses the reversal without a reason', function () {
    // The control for the control: a required field nobody proves is required is the silent half of
    // this change — an optional reason is not noticed until the reversal that matters has none.
    $budget = MarketingBudget::create([
        'asset_id' => $this->asset->id,
        'period_year' => (int) now()->year,
        'accrued_amount' => 500000,
    ]);
    $spend = MarketingSpend::create([
        'marketing_budget_id' => $budget->id,
        'category' => 'event', 'description' => 'Signage', 'amount' => 400,
        'paid_from' => 'bank', 'spent_on' => now()->toDateString(),
    ]);

    Livewire::test(MarketingSpendsRelationManager::class, [
        'ownerRecord' => $budget,
        'pageClass' => EditMarketingBudget::class,
    ])
        ->mountTableAction('reverse_document', $spend)
        ->setTableActionData(['reason' => ''])
        ->callMountedTableAction()
        ->assertHasTableActionErrors(['reason']);

    expect($spend->fresh()->trashed())->toBeFalse();
});

// ───────────── The preview says the FIGURES, and they are the ones that will move ─────────────

/**
 * CHANGE-IMPACT-PLAN §6.3 asked for *"this will reverse EGP 12,400 and re-post EGP 13,050"* and
 * deliberately did not build it — `wouldChange()` returns a boolean, and a sibling returning the
 * diff was called a separate piece. This is that piece, and the property that matters is not the
 * wording but that the figures are the ones `sync()` would actually move: both come from the same
 * `effectivePayload()` + `matches()` decision the engine makes.
 */
it('quotes the amount that would be reversed and the amount that would replace it', function () {
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $poster = app(LedgerPoster::class);
    expect($poster->pendingRestatement($this->expense->fresh()))->toBeNull();

    // Raise the cost. The live entry carries 1,000; a fresh post would carry 1,750.
    $this->expense->amount = 1750;
    $this->expense->total = 1750;
    $this->expense->saveQuietly();

    $pending = $poster->pendingRestatement($this->expense->fresh());

    expect($pending['from'])->toBe(1000.0)
        ->and($pending['to'])->toBe(1750.0);

    // …and those are the figures the sweep really moves.
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $live = JournalEntry::where('source_type', $this->expense->getMorphClass())
        ->where('source_id', $this->expense->id)->where('status', 'posted')->first();

    expect((float) $live->lines->sum('debit'))->toBe(1750.0)
        ->and($poster->pendingRestatement($this->expense->fresh()))->toBeNull();
});

it('says REVERSE with no replacement when the document loses its effect', function () {
    // Three shapes, three sentences, because an operator acts differently on each. A cancelled
    // document is reversed and nothing takes its place — quoting a "to" figure here would invent one.
    test()->artisan('accounting:sync-ledger', ['--all' => true])->assertExitCode(0);

    $this->expense->status = 'cancelled';
    $this->expense->saveQuietly();

    $pending = app(LedgerPoster::class)->pendingRestatement($this->expense->fresh());

    expect($pending['from'])->toBe(1000.0)
        ->and($pending['to'])->toBeNull();
});

it('says POST with no reversal when nothing has reached the ledger yet', function () {
    $pending = app(LedgerPoster::class)->pendingRestatement($this->expense);

    expect($pending['from'])->toBeNull()
        ->and($pending['to'])->toBe(1000.0);
});
