<?php

use App\Filament\Actions\ReversalReasonField;
use App\Filament\Admin\Resources\AccountingPeriods\Pages\ListAccountingPeriods;
use App\Filament\Admin\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Filament\Admin\Resources\VendorBills\Pages\EditVendorBill;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\Vendor;
use App\Models\VendorBill;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\PeriodService;
use App\Services\Accounting\YearEndCloseService;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Collection;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/**
 * **Closing a month, reopening it, reopening a year and reversing a manual journal are all on the
 * record — and every reopen and reversal says why.**
 *
 * Driven as the accountant (2026-09-13 workflow audit): a period close and reopen wrote NO audit
 * row at all (`AccountingPeriod` was not an audited model) and the reopen asked no reason — while
 * a reopen lifts `SealedPeriod`'s whole guard over every posting source, the single widest act in
 * the module. The benchmark's own rule, *"reopen sparingly and with proper documentation"*
 * (docs/benchmarks/yardi/02 §9), was documented in nobody's memory. The manual journal's void
 * meanwhile offered an OPTIONAL reason, alone among every reversal in the panel — and the shared
 * field every other reversal uses was labelled *Reason for Void*, so a cancelled bill refused with
 * *"The reason for Void field is required"*.
 *
 * One shape for all of them: `ActivityLogging::for()` on the period and the year, the required
 * `ReversalReasonField` on every reopen and reversal, `ReversalReason::record()` writing the why
 * onto the trail. Every refusal is paired with the control that the act still works with a reason.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset(['code' => 'CLR']);
    $this->user = makeUser('accounting', [$this->asset->id]);
    $this->actingAs($this->user);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);

    $this->accounts = app(AccountResolver::class);
    $this->march = AccountingPeriod::forDate(CarbonImmutable::create(2026, 3, 1));
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** The trail rows filed under one subject, oldest first. */
function trailOf(object $subject): Collection
{
    return Activity::query()
        ->where('subject_type', $subject->getMorphClass())
        ->where('subject_id', $subject->getKey())
        ->orderBy('id')
        ->get();
}

function postedRevenue(string $date, float $amount): JournalEntry
{
    return app(JournalPostingService::class)->post(['entry_date' => $date, 'lines' => [
        ['ledger_account_id' => app(AccountResolver::class)->id('accounts_receivable'), 'debit' => $amount, 'credit' => 0],
        ['ledger_account_id' => app(AccountResolver::class)->id('rent_revenue'), 'debit' => 0, 'credit' => $amount],
    ]]);
}

it('records who closed a month, and when', function () {
    // The calendar's own creation is on the record now too; what must not be there yet is a change.
    expect(trailOf($this->march)->where('event', 'updated'))->toBeEmpty();

    Livewire::test(ListAccountingPeriods::class)
        ->callTableAction('close_period', $this->march)
        ->assertHasNoTableActionErrors();

    $row = trailOf($this->march->fresh())->last();

    expect($this->march->fresh()->status)->toBe('closed')
        ->and($row)->not->toBeNull()
        ->and($row->log_name)->toBe('accounting_period')
        ->and($row->event)->toBe('updated')
        ->and($row->causer_id)->toBe($this->user->id)
        // The diff lives in `attribute_changes`, the column the Changes cell renders from.
        ->and($row->attribute_changes['old']['status'] ?? null)->toBe('open')
        ->and($row->attribute_changes['attributes']['status'] ?? null)->toBe('closed');
});

it('asks why a month is reopened, refuses silence, and files the answer', function () {
    Livewire::test(ListAccountingPeriods::class)->callTableAction('close_period', $this->march);

    // Refused: no reason. The period stays closed and nothing claims a reopen.
    Livewire::test(ListAccountingPeriods::class)
        ->callTableAction('reopen_period', $this->march->fresh(), data: ['reason' => ''])
        ->assertHasTableActionErrors(['reason']);

    expect($this->march->fresh()->status)->toBe('closed')
        ->and(trailOf($this->march)->where('event', 'reopened'))->toBeEmpty();

    // The control: a reason reopens it, and the reason is on the trail — not in an editable column.
    Livewire::test(ListAccountingPeriods::class)
        ->callTableAction('reopen_period', $this->march->fresh(), data: ['reason' => 'Late supplier bill for March'])
        ->assertHasNoTableActionErrors();

    $reopened = trailOf($this->march)->where('event', 'reopened')->last();

    expect($this->march->fresh()->status)->toBe('open')
        ->and($reopened)->not->toBeNull()
        ->and($reopened->log_name)->toBe('accounting_period')
        ->and($reopened->causer_id)->toBe($this->user->id)
        ->and(data_get($reopened->properties, 'reason'))->toBe('Late supplier bill for March')
        // The row reads as a sentence in both languages, not as a raw key.
        ->and(__('admin.activity.descriptions.accounting_period.reopened', [], 'ar'))->not->toBe(__('admin.activity.descriptions.accounting_period.reopened', [], 'en'));
});

it('asks why a year is reopened and files it on the fiscal year', function () {
    postedRevenue('2026-03-05', 3000);
    app(YearEndCloseService::class)->close(2026);
    $year = FiscalYear::where('year', 2026)->firstOrFail();

    expect(app(YearEndCloseService::class)->closingEntriesFor(2026))->toHaveCount(1);

    Livewire::test(ListAccountingPeriods::class)
        ->callAction('year_end_reopen', data: ['year' => 2026, 'reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect(app(YearEndCloseService::class)->closingEntriesFor(2026))->toHaveCount(1)
        ->and(trailOf($year)->where('event', 'reopened'))->toBeEmpty();

    Livewire::test(ListAccountingPeriods::class)
        ->callAction('year_end_reopen', data: ['year' => 2026, 'reason' => 'Auditor adjustment to March revenue'])
        ->assertHasNoActionErrors();

    $reopened = trailOf($year)->where('event', 'reopened')->last();

    expect(app(YearEndCloseService::class)->closingEntriesFor(2026))->toHaveCount(0)
        ->and($reopened)->not->toBeNull()
        ->and($reopened->log_name)->toBe('fiscal_year')
        ->and(data_get($reopened->properties, 'reason'))->toBe('Auditor adjustment to March revenue');
});

it('files the year\'s reopen even when no closing entry stands — the door the first cut missed', function () {
    // A year whose books never closed — months closed one by one under an open year, or a year with
    // no P&L movement — has no closing entry, so `YearEndCloseService::reopen()` returns before it
    // does anything. The reason was recorded THERE in the first cut, and the review measured the
    // real action on exactly this year: every month silently unlocked, the typed reason discarded,
    // zero rows. The record lives in `reopenFiscalYear()` now, the call every year reopen makes.
    $april = AccountingPeriod::forDate(CarbonImmutable::create(2026, 4, 1));
    Livewire::test(ListAccountingPeriods::class)->callTableAction('close_period', $this->march);
    Livewire::test(ListAccountingPeriods::class)->callTableAction('close_period', $april);
    $year = FiscalYear::where('year', 2026)->firstOrFail();

    expect(app(YearEndCloseService::class)->closingEntriesFor(2026))->toBeEmpty();

    Livewire::test(ListAccountingPeriods::class)
        ->callAction('year_end_reopen', data: ['year' => 2026, 'reason' => 'Closed the wrong months'])
        ->assertHasNoActionErrors();

    $reopened = trailOf($year)->where('event', 'reopened')->last();

    expect($this->march->fresh()->status)->toBe('open')
        ->and($april->fresh()->status)->toBe('open')
        ->and($reopened)->not->toBeNull()
        ->and(data_get($reopened->properties, 'reason'))->toBe('Closed the wrong months')
        // …and each month the year walked through has its own row: a model save, not a bulk update.
        ->and(trailOf($this->march)->where('event', 'updated')->last()?->attribute_changes['attributes']['status'] ?? null)->toBe('open')
        ->and(trailOf($april)->where('event', 'updated')->last()?->attribute_changes['attributes']['status'] ?? null)->toBe('open');
});

it('puts a row on every month a year\'s close walks through', function () {
    // `closeFiscalYear()` wrote the twelve months with one query-builder `update()`, which fires no
    // model event — measured: 12 periods flipped, 0 rows. A month closed with nobody's name on it
    // is the gap this whole change exists to close.
    postedRevenue('2026-03-05', 3000);
    app(YearEndCloseService::class)->close(2026);
    app(PeriodService::class)->closeFiscalYear(FiscalYear::where('year', 2026)->firstOrFail());

    $closedRows = Activity::query()
        ->where('subject_type', $this->march->getMorphClass())
        ->where('event', 'updated')
        ->get()
        ->filter(fn (Activity $row) => ($row->attribute_changes['attributes']['status'] ?? null) === 'closed');

    expect(AccountingPeriod::whereHas('fiscalYear', fn ($q) => $q->where('year', 2026))->where('status', 'closed')->count())->toBe(12)
        ->and($closedRows->pluck('subject_id')->unique())->toHaveCount(12)
        ->and($closedRows->every(fn (Activity $row) => $row->causer_id === $this->user->id))->toBeTrue();
});

it('requires a reason to reverse a manual journal, and files it beside the reversal', function () {
    $entry = postedRevenue('2026-03-05', 3000);

    Livewire::test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->callAction('void', data: ['reason' => ''])
        ->assertHasActionErrors(['reason']);

    expect($entry->fresh()->status)->toBe('posted');

    Livewire::test(EditJournalEntry::class, ['record' => $entry->getRouteKey()])
        ->callAction('void', data: ['reason' => 'Posted to the wrong tenant'])
        ->assertHasNoActionErrors();

    $voided = trailOf($entry)->where('event', 'voided')->last();
    $reversal = JournalEntry::where('reversal_of_id', $entry->id)->firstOrFail();

    expect($entry->fresh()->status)->toBe('void')
        ->and($voided)->not->toBeNull()
        ->and(data_get($voided->properties, 'reason'))->toBe('Posted to the wrong tenant')
        // …and the reversing entry's own narrative carries it too, as it always did.
        ->and($reversal->displayDescription())->toContain('Posted to the wrong tenant');
});

it('files nothing extra for the engine\'s own re-derives, which carry no person\'s words', function () {
    // `LedgerPoster::sync()` voids and re-posts on every re-derive with a programmer's key. A
    // trail row per automatic re-post would bury the ones a person wrote — the `updated` row
    // already says the entry was voided.
    $entry = postedRevenue('2026-03-05', 3000);

    app(JournalPostingService::class)->void($entry, reasonKey: 'reversal.superseded');

    expect($entry->fresh()->status)->toBe('void')
        ->and(trailOf($entry)->where('event', 'voided'))->toBeEmpty();
});

it('labels the one reason field as a reason, so a cancel does not ask for a "reason for void"', function () {
    foreach (['en', 'ar'] as $locale) {
        app()->setLocale($locale);
        expect((string) ReversalReasonField::make()->getLabel())->toBe(__('admin.fields.reason'))
            ->not->toBe(__('admin.fields.void_reason'));
    }
    app()->setLocale('en');

    // Read off the real cancel: the refusal names the field as a person reads it.
    $vendor = Vendor::create(['name' => 'Delta '.uniqid(), 'status' => Vendor::STATUS_ACTIVE]);
    $bill = VendorBill::create([
        'vendor_id' => $vendor->id, 'asset_id' => $this->asset->id, 'category' => 'cleaning_security',
        'status' => 'approved', 'bill_date' => '2026-03-05', 'due_date' => '2026-04-05',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000, 'balance' => 1000,
    ]);

    $errors = Livewire::test(EditVendorBill::class, ['record' => $bill->getRouteKey()])
        ->callAction('cancel_bill', data: ['reason' => ''])
        ->assertHasActionErrors(['reason'])
        ->errors()->all();

    expect(implode(' ', $errors))->toContain('The reason field is required')
        ->not->toContain('Void');
});
