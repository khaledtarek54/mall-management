<?php

use App\Filament\Admin\Pages\TrialBalance;
use App\Models\LedgerAccount;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerReportPdfService;
use App\Services\Accounting\LedgerReportService;
use App\Services\Reports\ReportCsvExporter;
use App\Support\IssuingEntity;
use App\Support\LedgerTree;
use App\Support\Pdf\PdfDocument;
use App\Support\ReportParameters;
use App\Support\StatementGroups;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Livewire\Livewire;

/**
 * **A trial balance reads as the chart's tree** — client meeting 2026-09-02, point 19: *"the trial
 * balance should be a tree: the parent general account, and under it the accounts, hide and show."*
 *
 * Until 2026-09-12 the statement listed every postable leaf flat, ordered by code. The chart is five
 * levels deep and every summary account already exists as a row (`is_postable = false`) with a
 * derived, self-healing `parent_id` — so the hierarchy was there and no report read it past the top
 * group. `App\Support\LedgerTree` is the one helper: every ancestor of a leaf on the statement is a
 * node carrying the sums of the leaves beneath it, debit and credit sides summed SEPARATELY, and
 * `visible()` is the one reading of which rows a fold hides. **The tree opens folded to its roots**
 * (the operator's ask the same day) and unfolds on click; the PDF prints the same nodes at the same
 * fold; the CSV carries the whole tree with a level per row, because a spreadsheet outlines it
 * itself; and the totals — the report's own, over the leaves — are the same at every fold.
 *
 * The fixture is built so a branch carries BOTH sides (a receivable and its allowance under `112`)
 * — a tree that nets a branch to one side prints a figure the totals row does not contain, and a
 * fixture where every branch is one-sided cannot tell the two apart. Two leaves share a parent
 * (`11101001` and `11102001` under `111`) so a fold has something to hide at depth.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear(2026);

    $r = app(AccountResolver::class);
    $this->post = fn (string $date, array $lines, ?int $assetId = null) => app(JournalPostingService::class)
        ->post(['entry_date' => $date, 'asset_id' => $assetId, 'lines' => $lines]);

    $this->bank = $r->id('bank');                       // 11102001
    $this->cash = $r->id('cash');                       // 11101001
    $this->ar = $r->id('accounts_receivable');          // 11201001
    $this->allowance = LedgerAccount::where('code', '11206001')->value('id');
    $this->capital = $r->id('capital');                 // 31101001
    $this->rent = $r->id('rent_revenue');               // 41101001
    $this->salaries = $r->id('salaries_expense');       // 51101001

    $this->line = fn (int $id, float $dr, float $cr = 0): array => ['ledger_account_id' => $id, 'debit' => $dr, 'credit' => $cr];

    $this->books = function (?int $assetId = null): void {
        ($this->post)('2026-07-15', [($this->line)($this->bank, 100000), ($this->line)($this->capital, 0, 100000)], $assetId);
        ($this->post)('2026-08-03', [($this->line)($this->cash, 2000), ($this->line)($this->bank, 0, 2000)], $assetId);
        ($this->post)('2026-08-05', [($this->line)($this->ar, 5000), ($this->line)($this->rent, 0, 5000)], $assetId);
        ($this->post)('2026-08-10', [($this->line)($this->salaries, 17000), ($this->line)($this->bank, 0, 17000)], $assetId);
        // The allowance is a CREDIT under the same branch (`112`) as the receivable's DEBIT.
        ($this->post)('2026-08-12', [($this->line)($this->salaries, 1000), ($this->line)($this->allowance, 0, 1000)], $assetId);
    };

    $this->from = CarbonImmutable::create(2026, 8, 1)->startOfDay();
    $this->to = CarbonImmutable::create(2026, 8, 31)->endOfDay();

    $this->node = function (array $nodes, string $code): array {
        $node = collect($nodes)->firstWhere('code', $code);
        expect($node)->not->toBeNull("node {$code} is missing from the tree");

        return $node;
    };

    $this->onScreen = function (): void {
        $this->seed(RolesPermissionsSeeder::class);
        $this->asset = makeAsset();
        $this->actingAs(makeUser('super_admin', [$this->asset->id]));
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        Filament::setTenant($this->asset);
        ($this->books)($this->asset->id);
    };
});

afterEach(fn () => Filament::setTenant(null, isQuiet: true));

it('rolls every leaf up into every ancestor, each side summed on its own, and the roots foot to the totals', function () {
    ($this->books)();
    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);
    $tree = $report['tree'];

    // Every ancestor of every leaf is a node — five levels for the bank.
    foreach (['1', '11', '111', '11102', '11102001'] as $code) {
        ($this->node)($tree, $code);
    }

    // `111` carries both children: cash 2,000 + bank (100,000 − 2,000 − 17,000 = 81,000).
    $cashAndBanks = ($this->node)($tree, '111');
    expect($cashAndBanks['debit_balance'])->toBe(83000.0)
        ->and($cashAndBanks['credit_balance'])->toBe(0.0)
        ->and($cashAndBanks['opening_debit'])->toBe(100000.0)
        ->and($cashAndBanks['debit_total'])->toBe(2000.0)
        ->and($cashAndBanks['credit_total'])->toBe(19000.0)
        ->and($cashAndBanks['is_leaf'])->toBeFalse()
        ->and($cashAndBanks['has_children'])->toBeTrue();

    // `112` holds a DEBIT receivable and a CREDIT allowance: both sides, never a net 4,000.
    $receivables = ($this->node)($tree, '112');
    expect($receivables['debit_balance'])->toBe(5000.0)
        ->and($receivables['credit_balance'])->toBe(1000.0);

    // The roots sum to the report's own totals — a folded-to-the-roots statement still foots.
    $roots = collect($tree)->where('depth', 0);
    expect($roots->pluck('code')->all())->toBe(['1', '3', '4', '5'])
        ->and($roots->sum('debit_balance'))->toBe((float) $report['total_debit'])
        ->and($roots->sum('credit_balance'))->toBe((float) $report['total_credit'])
        ->and($roots->sum('opening_debit'))->toBe((float) $report['total_opening_debit'])
        ->and($roots->sum('credit_total'))->toBe((float) $report['total_movement_credit']);

    // …and so does every level: the sum of the nodes at depth N is the sum of the leaves.
    $leafTotal = collect($report['rows'])->sum('debit_balance');
    foreach ([1, 2] as $depth) {
        expect(collect($tree)->where('depth', $depth)->sum('debit_balance'))->toBe((float) $leafTotal);
    }

    // A leaf keeps its own figures, untouched.
    $bank = ($this->node)($tree, '11102001');
    expect($bank['is_leaf'])->toBeTrue()
        ->and($bank['has_children'])->toBeFalse()
        ->and($bank['debit_balance'])->toBe(81000.0)
        ->and($bank['depth'])->toBe(4)
        ->and($bank['parent_code'])->toBe('11102');
});

it('walks the tree depth-first in chart order, a parent before its children and siblings by code', function () {
    ($this->books)();
    $codes = collect(app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to)['tree'])->pluck('code')->all();

    // Assets first, then within them the cash branch before the receivables branch, each parent
    // directly ahead of its subtree.
    expect(array_slice($codes, 0, 6))->toBe(['1', '11', '111', '11101', '11101001', '11102'])
        ->and(array_search('112', $codes, true))->toBeGreaterThan(array_search('11102001', $codes, true))
        ->and(array_search('3', $codes, true))->toBeGreaterThan(array_search('11206001', $codes, true));

    // Every parent precedes each of its children in the walk.
    $index = array_flip($codes);
    foreach (app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to)['tree'] as $node) {
        if ($node['parent_code'] !== null) {
            expect($index[$node['parent_code']])->toBeLessThan($index[$node['code']]);
        }
    }
});

it('opens folded to the roots, shows a branch only once every ancestor is unfolded, and unfolding all shows every node', function () {
    ($this->books)();
    $tree = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to)['tree'];

    // Nothing unfolded — the default: exactly the roots, marked folded, and they still foot.
    $roots = collect(LedgerTree::visible($tree));
    expect($roots->pluck('code')->all())->toBe(['1', '3', '4', '5'])
        ->and($roots->pluck('collapsed')->all())->toBe([true, true, true, true])
        ->and($roots->sum('debit_balance'))->toBe($roots->sum('credit_balance'));

    // Unfold a ROOT: its children appear, folded; the grandchildren stay hidden until their own
    // parent is opened — a node shows only when EVERY ancestor is open.
    $shown = collect(LedgerTree::visible($tree, ['1']));
    expect($shown->pluck('code')->all())->toBe(['1', '11', '3', '4', '5'])
        ->and($shown->firstWhere('code', '1')['collapsed'])->toBeFalse()
        ->and($shown->firstWhere('code', '11')['collapsed'])->toBeTrue();

    // Unfold a MID-level node whose ancestors are closed: nothing changes, it is not on show.
    expect(collect(LedgerTree::visible($tree, ['111']))->pluck('code')->all())->toBe(['1', '3', '4', '5']);

    // Open the path down to `111`: its two children appear; `112` beside it stays folded.
    $shown = collect(LedgerTree::visible($tree, ['1', '11', '111']))->pluck('code');
    expect($shown)->toContain('1', '11', '111', '11101', '11102', '112')
        ->not->toContain('11101001', '11102001', '11201001');

    // Unfold all: every node, in the same order, none marked folded; a leaf is never "collapsed".
    $parents = LedgerTree::parentCodes($tree);
    expect($parents)->toContain('1', '11', '111', '11102', '112')
        ->not->toContain('11102001', '11206001');
    $all = collect(LedgerTree::visible($tree, $parents));
    expect($all->pluck('code')->all())->toBe(collect($tree)->pluck('code')->all())
        ->and($all->pluck('collapsed')->unique()->all())->toBe([false]);
});

it('builds the same tree whatever order the leaf rows arrive in, a postable parent included', function () {
    // `build()` takes any iterable. The report hands it rows sorted by code — parents first — so
    // the branch that merges a row into a node an EARLIER leaf already created as an ancestor is
    // reached only when a caller hands the child before its parent. Driven directly, both orders.
    LedgerAccount::where('code', '111')->update(['is_postable' => true]);
    $chart = fn (string $code) => LedgerAccount::where('code', $code)->firstOrFail();
    $row = fn (string $code, float $dr): array => [
        'account_id' => $chart($code)->id, 'code' => $code, 'name_en' => $chart($code)->name_en,
        'name_ar' => $chart($code)->name_ar, 'type' => 'asset', 'debit_balance' => $dr, 'credit_balance' => 0.0,
    ];
    $sorted = LedgerTree::build([$row('111', 500), $row('11101001', 2000), $row('11102001', 81000)], ['debit_balance', 'credit_balance']);
    $reversed = LedgerTree::build([$row('11102001', 81000), $row('11101001', 2000), $row('111', 500)], ['debit_balance', 'credit_balance']);

    expect(collect($sorted)->pluck('code')->all())->toBe(collect($reversed)->pluck('code')->all())
        ->and(collect($sorted)->firstWhere('code', '111'))->toEqual(collect($reversed)->firstWhere('code', '111'))
        ->and(collect($reversed)->firstWhere('code', '111')['debit_balance'])->toBe(83500.0)
        ->and(collect($reversed)->firstWhere('code', '111')['is_leaf'])->toBeTrue()
        ->and(collect($reversed)->firstWhere('code', '111')['has_children'])->toBeTrue()
        ->and(collect($reversed)->firstWhere('code', '1')['debit_balance'])->toBe(83500.0);
});

it('orders siblings as code strings — the row order the flat listing and the general ledger use', function () {
    // On the shipped fixed-width chart every compare agrees; an imported mixed-width chart is where
    // they part: `9` sorts before `10` naturally and after it as a string, and the database gives
    // the general ledger the string order. The tree takes the listing's own rule so a leaf stands
    // in the tree where it stood in the flat statement.
    // Two roots outside the shipped 1–5 ranges (a code under one of those is that root's child by
    // prefix): `9` and `88` — natural order says 9 < 88, string order says '88' < '9'.
    foreach ([['9', 'Nine', 'expense'], ['88', 'Eighty-eight', 'asset'], ['901', 'Nine-one', 'expense'], ['8801', 'Eighty-eight-one', 'asset']] as [$code, $name, $type]) {
        LedgerAccount::create(['code' => $code, 'name_en' => $name, 'name_ar' => $name, 'type' => $type, 'is_postable' => strlen($code) > 2, 'is_active' => true]);
    }
    $id = fn (string $code) => LedgerAccount::where('code', $code)->value('id');
    expect(LedgerAccount::find($id('8801'))->parent_id)->toBe($id('88'));
    ($this->post)('2026-08-20', [($this->line)($id('901'), 300), ($this->line)($id('8801'), 0, 300)]);

    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);
    $roots = collect($report['tree'])->where('depth', 0)->pluck('code')->values()->all();

    expect($roots)->toBe(['88', '9'])
        ->and(collect($report['rows'])->pluck('code')->all())->toBe(['8801', '901']);
});

it('survives a cycle a hand-edited parent_id could write: nothing summed twice, no leaf dropped', function () {
    // `LedgerAccount::saving` derives the parent from a strict code prefix, so the app cannot write
    // one; a raw edit can. The walk stops at the first id it has seen, and a node whose ancestry
    // never reaches the top is placed at the root rather than lost.
    ($this->books)();
    $cashAndBanks = LedgerAccount::where('code', '111')->value('id');
    $current = LedgerAccount::where('code', '11')->value('id');
    DB::table('ledger_accounts')->where('id', $current)->update(['parent_id' => $cashAndBanks]);
    StatementGroups::forgetChart();

    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);
    $tree = collect($report['tree']);

    // Every leaf the totals count is on the tree, exactly once, with its own figure intact — and
    // no node carries more than the whole statement, which is what a walk that revisits the loop
    // produces (sixteen copies of the bank under `111`). The roll-ups of the two accounts IN the
    // loop are each other's and are not asserted: a chart that loops has no right answer for
    // them, only a wrong one to refuse.
    expect($tree->where('is_leaf', true)->pluck('code')->sort()->values()->all())
        ->toBe(collect($report['rows'])->pluck('code')->sort()->values()->all())
        ->and($tree->where('code', '11102001')->count())->toBe(1)
        ->and($tree->firstWhere('code', '11102001')['debit_balance'])->toBe(81000.0)
        ->and($tree->max('debit_balance'))->toBeLessThanOrEqual((float) $report['total_debit'])
        ->and($tree->firstWhere('code', '11102')['debit_balance'])->toBe(81000.0);
});

it('reads the chart as it now stands: the memo is dropped on every account write', function () {
    // The memo lives in the container, which on a queue worker is one long-lived process; without
    // the forget, a leaf created after the first statement of the day landed at the root of every
    // later one. `saved`, `deleted` and `restored` each drop it.
    $before = StatementGroups::chart();
    $fresh = LedgerAccount::create(['code' => '11102009', 'name_en' => 'New bank', 'name_ar' => 'بنك جديد', 'type' => 'asset', 'is_postable' => true, 'is_active' => true]);

    expect($before)->not->toHaveKey($fresh->id)
        ->and(StatementGroups::chart())->toHaveKey($fresh->id)
        ->and(StatementGroups::chart()[$fresh->id]['parent_id'])->toBe(LedgerAccount::where('code', '11102')->value('id'));

    $fresh->delete();
    // Trashed rows stay in the chart (a statement can carry a retired account's history) — the
    // point is that the memo was re-read, which the `restored` twin proves the other way.
    expect(StatementGroups::chart())->toHaveKey($fresh->id);
    $fresh->restore();
    expect(StatementGroups::chart())->toHaveKey($fresh->id);
});

it('keeps a leaf the chart never placed at the root, still counted', function () {
    // A single-character code has no prefix, so `LedgerAccount::saving` derives no parent; it is a
    // custom range (no leading-digit type rule), so the type is free.
    $orphan = LedgerAccount::create(['code' => '7', 'name_en' => 'Unplaced', 'name_ar' => 'غير مصنف', 'type' => 'expense', 'is_postable' => true, 'is_active' => true]);
    expect($orphan->parent_id)->toBeNull();

    ($this->post)('2026-08-20', [($this->line)($orphan->id, 300), ($this->line)($this->capital, 0, 300)]);
    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);

    $node = ($this->node)($report['tree'], '7');
    expect($node['depth'])->toBe(0)
        ->and($node['parent_code'])->toBeNull()
        ->and($node['is_leaf'])->toBeTrue()
        ->and($node['has_children'])->toBeFalse()
        ->and(collect($report['tree'])->where('depth', 0)->sum('debit_balance'))->toBe((float) $report['total_debit']);
});

it('lets a postable parent on an imported chart be both a row and a branch, its roll-up including its own postings', function () {
    // EG-28 places no rule against a summary account being postable — an imported chart may post
    // to `111` directly. Such a node is a leaf (its own ledger, its own link) AND a parent (a fold
    // control), and its figures are its own plus the leaves beneath.
    LedgerAccount::where('code', '111')->update(['is_postable' => true]);
    $cashAndBanks = LedgerAccount::where('code', '111')->value('id');

    ($this->books)();
    // Posted LAST, so the node already exists as an ancestor when its own row arrives — the order
    // in which overwriting would have dropped the children's sums.
    ($this->post)('2026-08-25', [($this->line)($cashAndBanks, 500), ($this->line)($this->capital, 0, 500)]);

    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);
    $node = ($this->node)($report['tree'], '111');

    expect($node['is_leaf'])->toBeTrue()
        ->and($node['has_children'])->toBeTrue()
        ->and($node['debit_balance'])->toBe(83500.0)
        // The report's own row for `111` still carries 500 alone — the tree never rewrote it.
        ->and(collect($report['rows'])->firstWhere('code', '111')['debit_balance'])->toBe(500.0)
        // And it is present exactly once.
        ->and(collect($report['tree'])->where('code', '111')->count())->toBe(1);
});

it('opens folded to the roots on the screen, unfolds from the code cell, and the fold is not a saved parameter', function () {
    ($this->onScreen)();

    $page = Livewire::test(TrialBalance::class)->set('year', 2026)->set('period', '2026-08')->assertOk();
    $codes = fn () => collect($page->instance()->getTableRecords())->pluck('code')->all();

    // Opens MINIMISED: the roots alone, and the totals row still foots beneath them.
    expect($codes())->toBe(['1', '3', '4', '5'])
        ->and($page->get('expanded'))->toBe([]);

    // A root's code cell is the fold control.
    $page->assertSeeHtml(e("callTableColumnAction('code', '1')"));

    // The click, through Filament's own dispatch for a column action: one level opens at a time.
    $page->call('callTableColumnAction', 'code', '1');
    expect($page->get('expanded'))->toBe(['1'])
        ->and($codes())->toBe(['1', '11', '3', '4', '5']);
    $page->call('callTableColumnAction', 'code', '11')->call('callTableColumnAction', 'code', '111');
    expect($codes())->toContain('111', '11101', '11102', '112')
        ->not->toContain('11101001', '11102001', '11201001');

    // A leaf's code cell is plain text — no button to click.
    $page->call('callTableColumnAction', 'code', '11102');
    expect($codes())->toContain('11102001');
    $page->assertSeeHtml(e("callTableColumnAction('code', '11102')"))
        ->assertDontSeeHtml(e("callTableColumnAction('code', '11102001')"));

    // Clicking an open node folds it again — and everything beneath it, however deep.
    $page->call('callTableColumnAction', 'code', '11');
    expect($page->get('expanded'))->toBe(['1', '111', '11102'])
        ->and($codes())->toBe(['1', '11', '3', '4', '5']);

    // Unfold all brings every node; fold all leaves the roots.
    $page->callAction('expand_all');
    expect($codes())->toContain('11102001', '11206001', '51101001');
    $page->callAction('collapse_all');
    expect($codes())->toBe(['1', '3', '4', '5']);

    // A summary account has no ledger of its own: no drill-down, where a leaf keeps its link.
    $page->callAction('expand_all');
    $column = $page->instance()->getTable()->getColumn('account');
    $rows = collect($page->instance()->getTableRecords());
    expect($column->record($rows->firstWhere('code', '111'))->getUrl())->toBeNull()
        ->and($column->record($rows->firstWhere('code', '11102001'))->getUrl())->not->toBeNull();

    // The fold is a browsing state: a saved view carries the parameters and not the fold.
    expect($page->get('expanded'))->not->toBe([])
        ->and(array_keys(ReportParameters::snapshot($page->instance())))->not->toContain('expanded')
        ->and(array_keys(ReportParameters::snapshotForSavedView($page->instance())))->not->toContain('expanded');

    // …and a fresh mount opens folded again, whatever the last visit unfolded.
    expect(Livewire::test(TrialBalance::class)->set('year', 2026)->set('period', '2026-08')->get('expanded'))->toBe([]);

    // A payload naming a LEAF is ignored — there is nothing beneath it to unfold, and recording it
    // would let a crafted call grow the state with codes no click can produce.
    $page->callAction('collapse_all')->call('toggleNode', '11102001');
    expect($page->get('expanded'))->toBe([]);
});

it('hands the fold on screen to the PDF it downloads', function () {
    // The service seam is proved above; this is the PAGE's half — delete `expanded:` from the
    // download action and only this goes red.
    ($this->onScreen)();

    $seen = new ArrayObject;
    app()->bind(LedgerReportPdfService::class, fn () => new class($seen, app(LedgerReportService::class)) extends LedgerReportPdfService
    {
        public function __construct(private ArrayObject $seen, LedgerReportService $reports)
        {
            parent::__construct($reports);
        }

        public function trialBalance(?array $assetIds, CarbonInterface $from, CarbonInterface $to, string $property, string $period, ?string $locale = null, bool $includeZeroBalances = false, array $expanded = []): string
        {
            $this->seen[] = $expanded;

            return '%PDF-1.4';
        }
    });

    Livewire::test(TrialBalance::class)
        ->set('year', 2026)
        ->set('period', '2026-08')
        ->call('callTableColumnAction', 'code', '1')
        ->call('callTableColumnAction', 'code', '11')
        ->callAction('download_pdf')
        ->assertHasNoActionErrors();

    expect($seen->getArrayCopy())->toBe([['1', '11']]);
});

it('prints the tree on the PDF at the fold the screen was at, with the totals over the leaves', function () {
    ($this->books)();
    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);

    $render = fn (array $expanded): string => PdfDocument::make('accounting.pdf.trial-balance')
        ->data([
            'report' => $report,
            'nodes' => LedgerTree::visible($report['tree'], $expanded),
            'meta' => ['property' => 'Consolidated', 'period' => 'Aug 2026', 'generated_on' => '31/08/2026', 'locale' => 'en'],
            ...IssuingEntity::forViewScopedTo(null),
        ])
        ->html();

    // Unfolded: the summary row `111` prints as a subtotal row with its roll-up, ahead of its
    // leaves, and a leaf row is plain. (The ROW's class, not the stylesheet's — the layout's own
    // CSS names `.subtotal-row`, so a bare substring match passes with no row marked at all.)
    $html = $render(LedgerTree::parentCodes($report['tree']));
    expect($html)->toMatch('/<tr class="subtotal-row">\s*<td class="code"[^>]*>111<\/td>\s*<td>Cash &amp; Banks<\/td>.*?83,000\.00/s')
        ->toMatch('/<tr>\s*<td class="code"[^>]*>11102001<\/td>/')
        ->toContain('>11102001<')
        // Indented in the reading direction, by depth.
        ->toMatch('/padding-left: 30px;">111</')
        ->toMatch('/padding-left: 54px;">11102001</');
    expect(strpos($html, '>111<'))->toBeLessThan(strpos($html, '>11102001<'));

    // Folded to the roots — how the screen opens: five lines, and the totals have not moved.
    $folded = $render([]);
    expect($folded)->toContain('>1<')->not->toContain('>11102001<')->not->toContain('>111<')->not->toContain('>11<');
    preg_match_all('/106,000\.00/', $folded, $m);
    expect(count($m[0]))->toBeGreaterThanOrEqual(2);

    // The SERVICE passes the fold it was given into the template — captured off the view rather
    // than inflated out of mpdf's streams.
    $seen = null;
    View::composer('accounting.pdf.trial-balance', function ($view) use (&$seen): void {
        $seen = collect($view->getData()['nodes'])->pluck('code')->all();
    });
    app(LedgerReportPdfService::class)->trialBalance(null, $this->from, $this->to, 'Consolidated', 'Aug 2026', expanded: ['1', '11']);
    expect($seen)->toBe(['1', '11', '111', '112', '3', '4', '5']);

    // Left unsaid, it prints what the screen opens on — the roots.
    app(LedgerReportPdfService::class)->trialBalance(null, $this->from, $this->to, 'Consolidated', 'Aug 2026');
    expect($seen)->toBe(['1', '3', '4', '5']);
});

it('exports the WHOLE tree to CSV with a level per row, whatever the screen has folded, still footing', function () {
    ($this->books)();
    $report = app(LedgerReportService::class)->trialBalance(null, $this->from, $this->to);

    $csv = app(ReportCsvExporter::class)->trialBalance($report);
    // code · account · type · opening Dr · opening Cr · debit · credit · closing Dr · closing Cr ·
    // LEVEL — last, so a template built on the nine columns this file always had does not shift.
    expect($csv['headers'])->toHaveCount(10)
        ->and($csv['headers'][9])->toBe(__('admin.reports.tree.level'));

    $row = fn (array $csv, string $code) => collect($csv['rows'])->first(fn (array $r): bool => $r[0] === $code);
    expect($row($csv, '1')[9])->toBe(0)
        ->and($row($csv, '111')[9])->toBe(2)
        ->and($row($csv, '11102001')[9])->toBe(4)
        ->and(array_slice($row($csv, '111'), 3, 6))->toBe([100000.0, 0.0, 2000.0, 19000.0, 83000.0, 0.0])
        ->and(array_slice($row($csv, '112'), 3, 6))->toBe([0.0, 0.0, 5000.0, 1000.0, 5000.0, 1000.0]);

    // Every node the tree holds is a row — nothing the fold on screen hides leaves the file.
    expect(collect($csv['rows'])->pluck(0)->all())->toContain(...collect($report['tree'])->pluck('code')->all());

    // The totals line is the leaves' — a summary row is never added into it.
    $totals = collect($csv['rows'])->first(fn (array $r): bool => $r[1] === __('admin.reports.csv.total'));
    expect(array_slice($totals, 3, 6))->toBe([100000.0, 100000.0, 25000.0, 25000.0, 106000.0, 106000.0])
        ->and($totals[9])->toBe('');

    // …and the page's download is the same file with the screen folded to its roots: a spreadsheet
    // outlines by the level column itself, so a file at the fold would have thrown rows away.
    ($this->onScreen)();
    $page = Livewire::test(TrialBalance::class)->set('year', 2026)->set('period', '2026-08');
    expect($page->get('expanded'))->toBe([]);
    $fromPage = $page->instance()->reportCsv();
    expect(collect($fromPage['rows'])->pluck(0)->all())->toContain('1', '11', '111', '11102001', '51101001');
});

it('names the fold controls in both languages, with no raw key and no other system named', function () {
    foreach (['en', 'ar'] as $locale) {
        foreach (['collapse_all', 'expand_all', 'summary_of_branch', 'level'] as $key) {
            $text = __("admin.reports.tree.{$key}", [], $locale);
            expect($text)->not->toContain('admin.reports')
                ->and(preg_match('/yardi|sap|odoo|mri/i', $text))->toBe(0);
        }
    }
    expect(__('admin.reports.tree.collapse_all', [], 'ar'))->toMatch('/\p{Arabic}/u');
});
