<?php

/**
 * SW-121 — "View working" rendered the itemised owner P&L as one run-on line.
 *
 * `TextEntry` renders its state as HTML, and a newline is whitespace there. With a single scalar
 * state Filament emits ONE `<div>` and its stylesheet sets no `white-space`, so the working joined
 * with "\n" came out as "Base rent — EGP 100,000.00 Service charge — EGP 25,000.00 Cleaning — …".
 * This is what an owner reads to understand what they were paid, and it was the only place in the
 * app that showed a P&L as a paragraph — the statement PDF beside it has always drawn a table.
 *
 * Two things came out of measuring it and are pinned here too: the panel showed lines and a NET
 * with no side totals, so nothing on it could be added up; and expenses printed unsigned beside
 * revenue, where the PDF parenthesises them. Plus the locale ladder over `income_breakdown`, which
 * was written twice — once in the table and once in the statement template — and is now the model's.
 */

use App\Filament\Admin\Resources\OwnerStatementRuns\Pages\ListOwnerStatementRuns;
use App\Filament\Admin\Resources\OwnerStatementRuns\Tables\OwnerStatementRunsTable;
use App\Models\AccountingPeriod;
use App\Models\FiscalYear;
use App\Models\OwnerStatementRun;
use Database\Seeders\RolesPermissionsSeeder;
use Filament\Actions\Testing\TestAction;
use Filament\Infolists\Components\TextEntry;
use Livewire\Livewire;

beforeEach(function () {
    // Without the RBAC catalogue `makeUser()` mints a role with no permissions, the list page
    // refuses to mount, and every modal probe reads null — an error that looks like a harness fault
    // and is really an unauthorised operator.
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset(['code' => 'OSR']);

    $year = FiscalYear::create([
        'year' => 2026, 'starts_on' => '2026-01-01', 'ends_on' => '2026-12-31', 'status' => 'open',
    ]);
    $period = AccountingPeriod::create([
        'fiscal_year_id' => $year->id, 'period_no' => 7,
        'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'status' => 'open',
    ]);

    $this->run = OwnerStatementRun::create([
        'reference' => 'OSR-2026-0001',
        'asset_id' => $this->asset->id,
        'accounting_period_id' => $period->id,
        'period_start' => '2026-07-01',
        'period_end' => '2026-07-31',
        'posting_date' => '2026-07-31',
        'basis' => OwnerStatementRun::BASIS_ACCRUAL,
        'total_revenue' => 125000,
        'total_expense' => 30000,
        'net_operating_income' => 95000,
        'net_distributable' => 95000,
        'status' => OwnerStatementRun::STATUS_FINALISED,
        'income_breakdown' => [
            'revenue' => [
                ['code' => '41101', 'name_en' => 'Base rent', 'name_ar' => 'إيجار أساسي', 'amount' => 100000],
                ['code' => '41103', 'name_en' => 'Service charge', 'name_ar' => 'رسوم خدمات', 'amount' => 25000],
            ],
            'expense' => [
                ['code' => '51101', 'name_en' => 'Cleaning', 'name_ar' => 'نظافة', 'amount' => 18000],
                ['code' => '51107', 'name_en' => 'Depreciation', 'name_ar' => 'إهلاك', 'amount' => 12000],
            ],
        ],
    ]);
});

it('gives the working one entry per account, never one joined string', function () {
    $revenue = OwnerStatementRunsTable::workingLines($this->run, 'revenue');

    // The defect, exactly: a string here is a single scalar state, and Filament renders that as one
    // `<div>` whose newlines are HTML whitespace.
    expect($revenue)->toBeArray()->toHaveCount(2)
        ->and($revenue[0])->toBe('Base rent — EGP 100,000.00')
        ->and($revenue[1])->toBe('Service charge — EGP 25,000.00')
        // No line may carry another account: that is what "run-on" was.
        ->and($revenue[0])->not->toContain('Service charge')
        ->and(implode('', $revenue))->not->toContain("\n");
});

it('parenthesises a cost, so it cannot be read as income', function () {
    $expense = OwnerStatementRunsTable::workingLines($this->run, 'expense');

    expect($expense)->toBe([
        'Cleaning — (EGP 18,000.00)',
        'Depreciation — (EGP 12,000.00)',
    ]);
});

it('asks Filament for the list layout, which is what makes the lines separate', function () {
    $schema = OwnerStatementRunsTable::workingSchema($this->run);

    /** @var TextEntry $revenue */
    $revenue = $schema[0];
    /** @var TextEntry $expense */
    $expense = $schema[1];

    // An array state alone is not enough: without this, Filament comma-joins a multi-value state
    // onto one line, which is the same defect wearing a different separator.
    expect($revenue)->toBeInstanceOf(TextEntry::class)
        ->and($revenue->isListWithLineBreaks())->toBeTrue()
        ->and($expense->isListWithLineBreaks())->toBeTrue();
});

it('prints each side under its own frozen total, so the reader can add it up', function () {
    // The subtotal rows the PDF has always carried and the panel never had. Filament v4.11 has no
    // `getHelperText()` reader — `helperText()` composes into `belowContent()` — and a table
    // action's modal is NOT part of the list page's rendered HTML in this harness, so the honest
    // probe is the schema the modal is built FROM: the entries' own configuration, read where it is
    // composed. The mount case below separately proves that schema builds on the real page.
    $schema = OwnerStatementRunsTable::workingSchema($this->run);

    $source = file_get_contents(app_path('Filament/Admin/Resources/OwnerStatementRuns/Tables/OwnerStatementRunsTable.php'));

    // Three entries, each carrying its side's frozen figure in its helper line — asserted on the
    // composition, because the numbers are interpolated at build time from the run's own columns.
    expect($schema)->toHaveCount(3)
        ->and($source)->toContain("->helperText(__('admin.owner_statements.fields.total_revenue')")
        ->and($source)->toContain("->helperText(__('admin.owner_statements.fields.total_expense')");
});

it('names an account in the reader own language, from one definition', function () {
    // The ladder used to be written twice — here and in the statement template — so an owner could
    // be told two different things about one account.
    expect(collect($this->run->breakdownRows('revenue', 'en'))->pluck('name')->all())
        ->toBe(['Base rent', 'Service charge'])
        ->and(collect($this->run->breakdownRows('revenue', 'ar'))->pluck('name')->all())
        ->toBe(['إيجار أساسي', 'رسوم خدمات']);
});

it('still says something for a run generated before the snapshot existed', function () {
    // The control on the empty branch: a legacy run must show the "none" line, not an empty panel
    // that reads as a broken screen.
    $this->run->update(['income_breakdown' => null]);

    expect(OwnerStatementRunsTable::workingLines($this->run->fresh(), 'revenue'))
        ->toBe([__('admin.owner_statements.pdf.none')])
        ->and($this->run->fresh()->breakdownRows('expense'))->toBe([]);
});

it('opens the panel from the real list without a fatal', function () {
    // A modal schema is a CLOSURE: the page renders perfectly and the schema only runs when somebody
    // clicks — the `use ($get)` class of fatal. Mounting is the only place that proves it builds.
    // The modal's CONTENT is not in the list page's rendered HTML in this harness, so what the
    // accounts say is proven by the `workingLines` cases above; this case proves the real page can
    // build the schema those cases read.
    $this->actingAs(makeUser('super_admin', [$this->asset->id]));

    asTenant($this->asset, function () {
        $page = Livewire::test(ListOwnerStatementRuns::class)
            ->mountAction(TestAction::make('breakdown')->table($this->run))
            ->assertSuccessful();

        // The mount genuinely happened — a refused or hidden action mounts nothing and the
        // assertSuccessful above would pass anyway.
        expect($page->instance()->mountedActions)->not->toBeEmpty();
    });
});
