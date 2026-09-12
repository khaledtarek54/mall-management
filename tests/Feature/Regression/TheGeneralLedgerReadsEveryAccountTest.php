<?php

use App\Filament\Admin\Pages\GeneralLedger;
use App\Models\LedgerAccount;
use App\Models\SavedReport;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Support\IssuingEntity;
use App\Support\Pdf\Bidi;
use App\Support\Pdf\PdfDocument;
use App\Support\ReportParameters;
use Carbon\CarbonImmutable;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Livewire\Livewire;

/**
 * The general ledger reads EVERY account — and prints (the reports audit, 2026-09-12).
 *
 * The GL page answered one account at a time and had no PDF, while the four statements and the
 * trial balance print. The GL an accountant reviews at month end, and the one an auditor asks for,
 * is every account with movement in the period — each with its opening, its lines and its closing,
 * in chart order — which here was ~40 exports per period. `LedgerReportService::generalLedger()` is
 * that reading, each account through the SAME `accountLedger()`, so a figure on the full ledger is
 * the figure on that account's own statement.
 *
 * The rules a tooth pins: an account with a standing balance and no movement still prints (its
 * opening is its closing, and a ledger that silently left it out would not foot to the trial
 * balance beside it); an account that opened at zero and moved nowhere does not; the reading is
 * property-scoped; the toggle carries as a saved-view parameter so a scheduled delivery can send
 * the whole ledger; and the printed copy is the same rows under the same headings.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $this->asset = makeAsset();
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs(makeUser('accounting', [$this->asset->id]));
    Filament::setTenant($this->asset);
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

/** Dr `$debit` / Cr `$credit` for `$amount` on `$on`, in `$assetId`'s books. */
function glEveryPost(string $debit, string $credit, float $amount, string $on, ?int $assetId = null): void
{
    app(JournalPostingService::class)->post([
        'entry_date' => $on,
        'asset_id' => $assetId,
        'description_en' => "{$debit}/{$credit} {$amount}",
        'lines' => [
            ['ledger_account_id' => LedgerAccount::where('code', $debit)->firstOrFail()->id, 'debit' => $amount, 'credit' => 0],
            ['ledger_account_id' => LedgerAccount::where('code', $credit)->firstOrFail()->id, 'debit' => 0, 'credit' => $amount],
        ],
    ]);
}

it('lists every account with movement or a standing balance in the window, in chart order, off the same statement', function () {
    // July: cash ← rent (both move). August: cash → repairs (cash moves again, repairs is new).
    // Rent therefore has a STANDING balance in August and no movement — it must still print.
    glEveryPost('11101001', '41101001', 10000, '2026-07-10', $this->asset->id);
    glEveryPost('51102001', '11101001', 2500, '2026-08-12', $this->asset->id);
    // An account that moved in JULY only and nets to zero by August: in, then out, before the window.
    glEveryPost('11203001', '11101001', 300, '2026-07-01', $this->asset->id);
    glEveryPost('11101001', '11203001', 300, '2026-07-02', $this->asset->id);

    $from = CarbonImmutable::parse('2026-08-01');
    $to = CarbonImmutable::parse('2026-08-31')->endOfDay();
    $reports = app(LedgerReportService::class);

    $ledger = $reports->generalLedger([$this->asset->id], $from, $to);

    expect($ledger->pluck('account.code')->all())
        // Chart order: cash · rent (standing) · repairs (new). Advances (zero, no movement) is out.
        ->toBe(['11101001', '41101001', '51102001']);

    $cash = $ledger[0];
    $rent = $ledger[1];

    expect($cash['opening'])->toBe(10000.0)->and($cash['closing'])->toBe(7500.0)->and($cash['lines'])->toHaveCount(1)
        ->and($rent['opening'])->toBe(10000.0)->and($rent['closing'])->toBe(10000.0)->and($rent['lines'])->toHaveCount(0);

    // Byte-for-byte the account's own statement — one arithmetic, two readings.
    $own = $reports->accountLedger($cash['account'], [$this->asset->id], $from, $to);

    expect([$cash['opening'], $cash['closing']])->toBe([$own['opening'], $own['closing']]);
});

it('reads one property only', function () {
    $other = makeAsset();
    glEveryPost('11101001', '41101001', 1000, '2026-08-05', $this->asset->id);
    // The other mall posts to the SAME cash account AND to accounts of its own: the first proves
    // each account's statement is scoped (a fixture on other accounts alone let an unscoped
    // `accountLedger()` pass, the candidate query covering for it), the second that no foreign
    // account is listed.
    glEveryPost('11101001', '41102001', 9999, '2026-08-05', $other->id);
    glEveryPost('11102001', '41102001', 5555, '2026-08-05', $other->id);

    $ledger = app(LedgerReportService::class)->generalLedger([$this->asset->id], CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')->endOfDay());

    expect($ledger->pluck('account.code')->all())->toBe(['11101001', '41101001'])
        ->and($ledger[0]['closing'])->toBe(1000.0)
        ->and($ledger[0]['lines'])->toHaveCount(1)
        // The control: the portfolio reading has both malls' accounts and both malls' cash.
        ->and(app(LedgerReportService::class)->generalLedger(null, CarbonImmutable::parse('2026-08-01'), CarbonImmutable::parse('2026-08-31')->endOfDay())->pluck('account.code')->all())
        ->toBe(['11101001', '11102001', '41101001', '41102001']);
});

it('shows every account on the page, each bracketed by its opening and closing under its heading — and one account as before', function () {
    glEveryPost('11101001', '41101001', 10000, '2026-08-10', $this->asset->id);
    glEveryPost('51102001', '11101001', 2500, '2026-08-12', $this->asset->id);

    $page = Livewire::test(GeneralLedger::class)
        ->set('year', 2026)
        ->set('period', '2026-08')
        ->set('allAccounts', true)
        ->assertOk();

    $records = $page->instance()->getTable()->getRecords()->items();
    $kinds = collect($records)->map(fn (array $r): string => match (true) {
        $r['is_opening'] => 'open:'.$r['account_code'],
        $r['is_closing'] => 'close:'.$r['account_code'],
        default => 'line:'.$r['account_code'],
    })->all();

    expect($kinds)->toBe([
        'open:11101001', 'line:11101001', 'line:11101001', 'close:11101001',
        'open:41101001', 'line:41101001', 'close:41101001',
        'open:51102001', 'line:51102001', 'close:51102001',
    ])
        ->and(collect($records)->firstWhere('is_closing', true)['running_balance'])->toBe(7500.0)
        ->and($page->instance()->getSubheading())->toContain(trans_choice('admin.reports.accounts_with_movement', 3, ['count' => 3]))
        // Grouped under the account's heading, through the table's own grouping — asserted on the
        // GROUP HEADING element, because the disabled account picker's option list also carries
        // "11101001 — Main Cashier" and a bare `toContain` of it was green with grouping off.
        ->and(preg_replace('/\s+/u', ' ', preg_replace('/<!--.*?-->/s', '', $page->html())))->toContain(__('admin.reports.account').': 11101001 — Main Cashier');

    // Back to one account: the closing is the subheading and there is no closing row, as always.
    $cash = LedgerAccount::where('code', '11101001')->firstOrFail();
    $single = $page->set('allAccounts', false)->set('accountId', $cash->id)->assertOk();

    expect(collect($single->instance()->getTable()->getRecords()->items())->where('is_closing', true))->toBeEmpty()
        ->and($single->instance()->getSubheading())->toContain(__('admin.reports.closing_balance').': EGP 7,500.00');
});

it('exports the whole ledger with the account named on every row, and refuses when neither an account nor every account is asked for', function () {
    glEveryPost('11101001', '41101001', 10000, '2026-08-10', $this->asset->id);

    $page = Livewire::test(GeneralLedger::class)->set('year', 2026)->set('period', '2026-08');

    // Neither: an unanswered question, refused in words (the control for the two exports below).
    expect(fn () => $page->instance()->reportCsv())->toThrow(DomainException::class, __('admin.reports.general_ledger_needs_account'));

    $all = $page->set('allAccounts', true)->instance()->reportCsv();
    // The toggle is REMEMBERED per reader (it is their shape, not their moment), so the second page
    // opens with it on and has to switch it off to read one account — as the picker, disabled
    // while the toggle is on, makes a person do.
    $one = Livewire::test(GeneralLedger::class)->set('year', 2026)->set('period', '2026-08')
        ->set('allAccounts', false)
        ->set('accountId', LedgerAccount::where('code', '11101001')->firstOrFail()->id)->instance()->reportCsv();

    expect($all['filename'])->toStartWith('general-ledger-all-')
        ->and(array_slice($all['headers'], 0, 2))->toBe([__('admin.reports.csv.account_code'), __('admin.reports.csv.account')])
        // The six columns after the account pair ARE the single-account export's: a template built
        // on that file reads the whole ledger by dropping two columns.
        ->and(array_slice($all['headers'], 2))->toBe($one['headers'])
        ->and(array_map(fn (array $r): array => array_slice($r, 2), array_filter($all['rows'], fn (array $r): bool => $r[0] === '11101001')))
        ->toBe($one['rows'])
        ->and(collect($all['rows'])->pluck(0)->unique()->values()->all())->toBe(['11101001', '41101001']);

    // The toggle is a saved-view PARAMETER, so a scheduled delivery can send the whole ledger.
    expect(ReportParameters::parametersOf(GeneralLedger::class))->toHaveKey('allAccounts');
});

it('opens on the account a statement row links to, even when the reader last read every account', function () {
    glEveryPost('11101001', '41101001', 10000, '2026-08-10', $this->asset->id);
    $cash = LedgerAccount::where('code', '11101001')->firstOrFail();

    // The reader leaves the page with "every account" on — remembered, as their shape.
    Livewire::test(GeneralLedger::class)->set('year', 2026)->set('period', '2026-08')->set('allAccounts', true)->assertOk();

    // The control: a bare open comes back on every account…
    expect(Livewire::test(GeneralLedger::class)->instance()->allAccounts)->toBeTrue();

    // …and a statement row's link names its account, so THAT is what opens — not forty accounts
    // with the one that was clicked somewhere among them.
    $this->get(GeneralLedger::getUrl(['accountId' => $cash->id, 'year' => 2026, 'period' => '2026-08']))
        ->assertOk()
        ->assertSee(__('admin.reports.closing_balance').': EGP 10,000.00')
        ->assertDontSee(trans_choice('admin.reports.accounts_with_movement', 2, ['count' => 2]));
});

it('opens a saved view of the whole ledger as the whole ledger', function () {
    // `ReportParameters::urlFor()` writes every declared parameter, and `ReportPreferences::restore()`
    // leaves a key the URL names alone — so unless mount READS `allAccounts`, the headline saved view
    // ("GL, every account, monthly") opened with the toggle off and a "choose an account" empty
    // state. Found by review, by opening the link.
    glEveryPost('11101001', '41101001', 10000, '2026-08-10', $this->asset->id);

    $this->get(GeneralLedger::getUrl(['allAccounts' => 1, 'year' => 2026, 'period' => '2026-08']))
        ->assertOk()
        ->assertSee(trans_choice('admin.reports.accounts_with_movement', 2, ['count' => 2]))
        // The empty state's hint, not the picker's placeholder — the disabled picker still renders
        // "— Choose an account —" as its placeholder whatever the table shows.
        ->assertDontSee(__('admin.reports.choose_account_hint'));

    // A view that states BOTH the toggle and an account is honoured as saved — the toggle wins,
    // exactly as it does on the page, where the picker is ignored while the toggle is on.
    $cash = LedgerAccount::where('code', '11101001')->firstOrFail();

    $this->get(GeneralLedger::getUrl(['allAccounts' => 1, 'accountId' => $cash->id, 'year' => 2026, 'period' => '2026-08']))
        ->assertOk()
        ->assertSee(trans_choice('admin.reports.accounts_with_movement', 2, ['count' => 2]));
});

it('groups from the first render when the reader last had every account on', function () {
    glEveryPost('11101001', '41101001', 10000, '2026-08-10', $this->asset->id);

    // Remembered on the way out…
    Livewire::test(GeneralLedger::class)->set('year', 2026)->set('period', '2026-08')->set('allAccounts', true)->assertOk();

    // …so a fresh mount must apply the grouping ITSELF: `$tableGrouping` is set in the request that
    // flips the toggle, and on this path no request flips it.
    $html = preg_replace('/\s+/u', ' ', preg_replace('/<!--.*?-->/s', '', Livewire::test(GeneralLedger::class)->set('year', 2026)->set('period', '2026-08')->assertOk()->html()));

    expect($html)->toContain(__('admin.reports.account').': 11101001 — Main Cashier');
});

it('starts a new statement on its first page', function () {
    glEveryPost('11101001', '41101001', 10000, '2026-08-10', $this->asset->id);

    // Forty accounts on page 3, then one account of two pages, was a page 3 of 2 — an empty table
    // under "no movements in this period" about an account with sixty lines.
    Livewire::test(GeneralLedger::class)
        ->set('year', 2026)->set('period', '2026-08')->set('allAccounts', true)
        ->set('paginators.page', 3)
        ->set('allAccounts', false)
        ->assertSet('paginators.page', 1)
        ->set('paginators.page', 3)
        ->set('accountId', LedgerAccount::where('code', '11101001')->firstOrFail()->id)
        ->assertSet('paginators.page', 1);
});

it('reads a view saved before the toggle existed as one account, whatever its owner last browsed', function () {
    glEveryPost('11101001', '41101001', 10000, '2026-08-10', $this->asset->id);
    $cash = LedgerAccount::where('code', '11101001')->firstOrFail();

    // A view saved on 2026-09-01: an account, no `allAccounts` key — the shape every GL view had.
    $old = SavedReport::create(['report' => 'general_ledger', 'name' => 'Cash, monthly', 'user_id' => auth()->id(), 'is_shared' => false,
        'parameters' => ['accountId' => $cash->id, 'year' => 2026, 'period' => '2026-08']]);
    $stated = SavedReport::create(['report' => 'general_ledger', 'name' => 'Everything', 'user_id' => auth()->id(), 'is_shared' => false,
        'parameters' => ['allAccounts' => true, 'year' => 2026]]);

    (require database_path('migrations/2026_09_13_200000_a_general_ledger_view_saved_before_the_toggle_means_one_account.php'))->up();

    expect($old->fresh()->parameters['allAccounts'])->toBeFalse()
        // A view that already says what it means is left alone.
        ->and($stated->fresh()->parameters['allAccounts'])->toBeTrue();

    // The owner browsed every account once (remembered); the delivery path mounts as them and then
    // applies the view — with the key stated, the view means the account it names.
    Livewire::test(GeneralLedger::class)->set('year', 2026)->set('period', '2026-08')->set('allAccounts', true)->assertOk();

    $page = Livewire::test(GeneralLedger::class)->instance();
    expect($page->allAccounts)->toBeTrue();   // the memory, before the view is applied

    ReportParameters::apply($page, $old->fresh()->parameters);

    expect($page->reportCsv()['filename'])->toStartWith('general-ledger-11101001');
});

it('prints — one account or every account — through the real service, with each account under its heading', function () {
    glEveryPost('11101001', '41101001', 10000, '2026-08-10', $this->asset->id);
    glEveryPost('51102001', '11101001', 2500, '2026-08-12', $this->asset->id);

    $page = Livewire::test(GeneralLedger::class)->set('year', 2026)->set('period', '2026-08');

    // Nothing to print until something is asked for; every account is something.
    $page->assertActionHidden('download_pdf');
    $page->set('allAccounts', true)->assertActionVisible('download_pdf');

    $svc = app(LedgerReportPdfService::class);
    $from = CarbonImmutable::parse('2026-08-01');
    $to = CarbonImmutable::parse('2026-08-31')->endOfDay();

    $all = $svc->generalLedger([$this->asset->id], $from, $to, 'A mall', 'August 2026', 'en');
    $one = $svc->generalLedger([$this->asset->id], $from, $to, 'A mall', 'August 2026', 'en', LedgerAccount::where('code', '11101001')->firstOrFail());
    $none = $svc->generalLedger([$this->asset->id], CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-01-31')->endOfDay(), 'A mall', 'January 2026', 'en');
    $arabic = $svc->generalLedger([$this->asset->id], $from, $to, 'A mall', 'August 2026', 'ar');

    // A PDF's text cannot be read without inflating its streams, so the tooth is what each
    // document WEIGHS, in ONE language (an Arabic document embeds a second face and weighs more
    // whatever it says): three accounts print more than one, and one more than none. A service
    // that rendered the template with no statements would hand back three documents of one size.
    expect($all)->toStartWith('%PDF')
        ->and($arabic)->toStartWith('%PDF')
        ->and(strlen($all))->toBeGreaterThan(strlen($one))
        ->and(strlen($one))->toBeGreaterThan(strlen($none));

    // The layout, on the HTML seam: heading → opening → lines → closing, per account, in order.
    $statements = app(LedgerReportService::class)->generalLedger([$this->asset->id], $from, $to);
    $html = PdfDocument::make('accounting.pdf.general-ledger')->locale('en')->data(fn (): array => [
        'statements' => $statements->map(fn (array $s): array => [
            'code' => $s['account']->code, 'name' => $s['account']->name_en, 'opening' => $s['opening'], 'closing' => $s['closing'],
            'lines' => $s['lines']->map(fn ($l): array => ['entry_date' => $l->entry_date, 'entry_number' => $l->entry_number, 'description' => (string) $l->description_en, 'debit' => (float) $l->debit, 'credit' => (float) $l->credit, 'running_balance' => (float) $l->running_balance])->all(),
        ])->all(),
        'single' => false,
        'meta' => ['property' => 'A mall', 'period' => 'August 2026', 'generated_on' => '01/09/2026', 'locale' => 'en'],
        ...IssuingEntity::forViewScopedTo(null),
    ])->html();

    $at = fn (string $needle, int $offset = 0): int => strpos($html, $needle, $offset) ?: throw new RuntimeException("Not printed: {$needle}");

    $cash = $at('11101001 — Main Cashier');
    $rent = $at('41101001 — Base Rent Revenue');

    expect($cash)->toBeLessThan($rent)
        ->and($at(__('admin.reports.opening_balance'), $cash))->toBeLessThan($at('51102001/11101001 2500', $cash))
        ->and($at('51102001/11101001 2500', $cash))->toBeLessThan($at(__('admin.reports.closing_balance'), $cash))
        ->and($at(__('admin.reports.closing_balance'), $cash))->toBeLessThan($rent)
        ->and($html)->toContain('EGP 7,500.00')
        // A bare figure is bidi-isolated, or an Arabic page prints `-338,003.70` as `338,003.70-`
        // on exactly the abnormal-side balances an auditor reads first.
        ->and($html)->toContain(Bidi::LRM.'10,000.00'.Bidi::LRM);
});
