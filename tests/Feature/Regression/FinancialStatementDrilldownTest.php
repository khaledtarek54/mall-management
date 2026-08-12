<?php

/*
|--------------------------------------------------------------------------
| A statement figure can be opened
|--------------------------------------------------------------------------
| The income statement, balance sheet, trial balance and general ledger were TERMINAL: the numbers
| were right and there was no way to ask what they were made of. An accountant checking a revenue
| line left the report, opened the ledger, re-picked the account, re-picked the period and re-picked
| the property — and that is the single biggest difference between this and the systems it is
| benchmarked against, where a statement line opens its entries and an entry opens its document.
|
| Every piece was already in the database. `journal_entries.source_type/source_id` names the
| document; the statement rows already carried `account_id`. Nothing was on any screen.
|
| Two properties matter beyond "the link exists":
|   1. **It carries the report's own scope.** Landing on "this year, all properties" answers a
|      different question from the one that was clicked.
|   2. **The id in the URL is clamped.** `assetId` is the property-isolation dimension and it now
|      arrives from a query string, which is exactly the shape that leaks another mall's books.
*/

use App\Filament\Admin\Pages\GeneralLedger;
use App\Filament\Admin\Pages\IncomeStatement;
use App\Filament\Admin\Pages\TrialBalance;
use App\Models\Invoice;
use App\Models\LedgerAccount;
use App\Support\SourceDocumentUrl;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);

    $this->asset = makeAsset(['code' => 'DR']);
    $this->actingAs(makeUser('super_admin'));
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('opens the general ledger on the account, period and property it was clicked from', function () {
    $account = LedgerAccount::where('is_postable', true)->firstOrFail();

    $url = GeneralLedger::getUrl([
        'accountId' => $account->id,
        'year' => 2026,
        'period' => '2026-03',
        'assetId' => $this->asset->id,
    ]);

    Livewire::test(GeneralLedger::class)
        ->assertOk();

    // The page must actually ADOPT them — `ScopesLedgerReport::mount()` set the year to today and
    // nothing else, so a drill-down link used to land on an empty "choose an account" page, which
    // is worse than no link at all.
    $page = new GeneralLedger;
    request()->merge([
        'accountId' => $account->id,
        'year' => 2026,
        'period' => '2026-03',
        'assetId' => $this->asset->id,
    ]);
    $page->mount();

    expect($page->accountId)->toBe($account->id)
        ->and($page->year)->toBe(2026)
        ->and($page->period)->toBe('2026-03')
        ->and($page->assetId)->toBe($this->asset->id)
        ->and($url)->toContain('accountId='.$account->id);
});

it('refuses a property id the operator cannot see', function () {
    // The hazard the drill-down introduces: `assetId` is the isolation dimension and it now arrives
    // in a URL. A restricted operator hand-editing it must not get another mall's ledger.
    $other = makeAsset(['code' => 'OTHER']);
    $restricted = makeUser('manager');
    $restricted->assignedAssets()->sync([$this->asset->id]);

    $this->actingAs($restricted);
    Filament::setTenant($this->asset);

    $page = new GeneralLedger;
    request()->merge(['assetId' => $other->id, 'year' => 2026]);
    $page->mount();

    expect($page->assetId)->toBeNull();

    // The control — their OWN property is adopted, so the clamp is a filter and not a blanket
    // refusal that would break the feature for everyone.
    $page = new GeneralLedger;
    request()->merge(['assetId' => $this->asset->id, 'year' => 2026]);
    $page->mount();

    expect($page->assetId)->toBe($this->asset->id);
});

it('ignores a malformed period rather than rendering a report headed nonsense', function () {
    $page = new GeneralLedger;
    request()->merge(['year' => 'not-a-year', 'period' => 'March']);
    $page->mount();

    expect($page->year)->toBe((int) now()->year)
        ->and($page->period)->toBeNull();
});

it('links a ledger line to the document that caused it', function () {
    $lease = makeLease(makeUnit($this->asset), makeTenant(), [
        'status' => 'active',
        'commencement_date' => '2026-01-01',
        'expiry_date' => '2026-12-31',
    ]);

    $invoice = Invoice::create([
        'lease_id' => $lease->id,
        'tenant_id' => $lease->tenant_id,
        'status' => 'issued',
        'issue_date' => '2026-03-01',
        'due_date' => '2026-03-08',
        'period_start' => '2026-03-01',
        'period_end' => '2026-03-31',
        'subtotal' => 1000, 'vat_amount' => 0, 'total' => 1000,
        'paid_amount' => 0, 'balance' => 1000, 'currency' => 'EGP',
    ]);

    expect(SourceDocumentUrl::for($invoice))->toContain('invoices')
        // …and resolved from the morph pair a report row actually carries, which is what the
        // ledger uses: it selects source_type/source_id as columns rather than hydrating a model
        // per line.
        ->and(SourceDocumentUrl::forSource($invoice->getMorphClass(), $invoice->id))
        ->toBe(SourceDocumentUrl::for($invoice));
});

it('renders a plain label rather than a dead link when there is nowhere to go', function () {
    // A manual journal entry has no source document, and a source whose record has been removed has
    // nothing to open. Both must return null: an operator who clicks into a 403 or a 404 learns the
    // system is broken, one who sees a label learns what the line is.
    expect(SourceDocumentUrl::for(null))->toBeNull()
        ->and(SourceDocumentUrl::forSource(null, null))->toBeNull()
        ->and(SourceDocumentUrl::forSource('invoice', 999999))->toBeNull();
});

it('sends nobody to a document they are not allowed to open', function () {
    // A general ledger is visible to more roles than the documents behind it. `viewer` can read the
    // books; if it could not open an invoice, the link must not offer the route.
    $lease = makeLease(makeUnit($this->asset), makeTenant(), ['status' => 'active']);
    $invoice = Invoice::create([
        'lease_id' => $lease->id, 'tenant_id' => $lease->tenant_id, 'status' => 'issued',
        'issue_date' => '2026-03-01', 'due_date' => '2026-03-08',
        'period_start' => '2026-03-01', 'period_end' => '2026-03-31',
        'subtotal' => 100, 'vat_amount' => 0, 'total' => 100,
        'paid_amount' => 0, 'balance' => 100, 'currency' => 'EGP',
    ]);

    // The control first: a role that MAY view gets a link.
    expect(SourceDocumentUrl::for($invoice))->not->toBeNull();

    $this->actingAs(makeUser('marketing'));
    app()->forgetInstance('atriom.source_url.'.$invoice->getMorphClass().'.'.$invoice->id);

    expect(SourceDocumentUrl::for($invoice))->toBeNull();
});

it('links an account row and leaves a total alone', function () {
    // The row-shape half, driven through the real concern: a statement's total is not an account,
    // so there is nothing to open, and a link on it would send the operator to a ledger of
    // "everything" that answers no question they asked.
    $page = new IncomeStatement;
    $page->mount();

    $method = new ReflectionMethod($page, 'ledgerUrlFor');
    $method->setAccessible(true);

    $account = LedgerAccount::where('is_postable', true)->firstOrFail();

    $accountRow = ['is_total' => false, 'account_id' => $account->id];
    $totalRow = ['is_total' => true, 'account_id' => null];
    $aggregateRow = ['is_total' => false, 'account_id' => null];

    expect($method->invoke($page, $accountRow))->toContain('accountId='.$account->id)
        // …and carries the report's own year rather than defaulting to today's.
        ->and($method->invoke($page, $accountRow))->toContain('year='.$page->year)
        ->and($method->invoke($page, $totalRow))->toBeNull()
        ->and($method->invoke($page, $aggregateRow))->toBeNull();
});

it('renders the statement pages with the drill-down wired', function () {
    // The pages must still render: the URL closure runs for every row, so a mistake in it is a
    // broken report rather than a missing link.
    Livewire::test(IncomeStatement::class)->assertOk();
    Livewire::test(TrialBalance::class)->assertOk();
    Livewire::test(GeneralLedger::class)->assertOk();
});
