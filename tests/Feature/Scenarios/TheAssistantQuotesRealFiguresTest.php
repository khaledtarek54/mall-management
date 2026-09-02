<?php

use App\Contracts\AssistantModel;
use App\Filament\Admin\Pages\ActivityLog;
use App\Filament\Admin\Pages\TrialBalance;
use App\Services\Assistant\AnswerQuestionService;
use App\Support\Assistant\AssistantCorpus;
use App\Support\Assistant\ReportRunner;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;

/**
 * PHASE B1a — the assistant answering from the property's own numbers.
 *
 * The model does NOT choose the report: retrieval already ranked it, and
 * `TheAssistantAnswersTheseQuestionsTest` pins that ranking. So there is no tool-calling loop to
 * test — what has to hold is that the FIGURES reach the model, that they are the reader's own, and
 * that a truncation is stated rather than silently applied.
 *
 * **Asserted on the passage, not on the prose.** What the model writes varies run to run; which
 * numbers it was given does not, and it is the half that decides whether the answer can be right.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    AssistantCorpus::flush();
});

afterEach(fn () => AssistantCorpus::flush());

/** Records what it was handed, so the passages can be inspected. */
function figureSpy(): AssistantModel
{
    return new class implements AssistantModel
    {
        public static array $seen = [];

        public function word(string $question, array $passages, string $locale): ?string
        {
            self::$seen = $passages;

            return 'An answer.';
        }

        public function lastUsage(): array
        {
            return ['input' => 1, 'output' => 1];
        }

        public function isConfigured(): bool
        {
            return true;
        }
    };
}

it('runs a report and hands back its rows', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $result = ReportRunner::run(TrialBalance::class);

        expect($result)->not->toBeNull()
            ->and($result['headers'])->not->toBeEmpty()
            ->and($result)->toHaveKeys(['rows', 'total', 'truncated']);
    });
});

it('refuses a report the reader may not open', function () {
    $asset = makeAsset();

    // The refusal: a technician holds no accounting rights.
    $this->actingAs(makeUser('technician'));
    asTenant($asset, fn () => expect(ReportRunner::run(TrialBalance::class))->toBeNull());

    // The control, in the same shape — otherwise a runner that returned null for everybody would
    // satisfy the refusal and read as a pass.
    auth()->forgetUser();
    $this->actingAs(makeUser('super_admin'));
    asTenant($asset, fn () => expect(ReportRunner::run(TrialBalance::class))->not->toBeNull());
});

it('never runs the two reports that cannot render outside Livewire', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    // Skipped BY NAME, not by catching the fatal: `$table` is a typed property initialised by
    // Livewire's boot, and catching `Error` to paper over a known structural limit is how it stops
    // being known. See EveryDeliverableReportCanActuallyRenderTest.
    asTenant($asset, fn () => expect(ReportRunner::run(ActivityLog::class))->toBeNull());

    expect(ReportRunner::CANNOT_RUN_HEADLESS)->toContain(ActivityLog::class);
});

it('states the tail rather than truncating in silence', function () {
    $rows = array_map(fn (int $i): array => ["row {$i}", $i], range(1, ReportRunner::MAX_ROWS + 40));

    $text = ReportRunner::asText([
        'headers' => ['Name', 'Amount'],
        'rows' => array_slice($rows, 0, ReportRunner::MAX_ROWS),
        'total' => count($rows),
        'truncated' => true,
    ]);

    // "The top 25 debtors" and "your debtors" are different claims, and a cut the reader cannot see
    // turns the first into the second.
    expect($text)->toContain((string) count($rows))
        ->and($text)->toContain((string) ReportRunner::MAX_ROWS);
});

it('puts the real figures in front of the model', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    app()->instance(AssistantModel::class, figureSpy());

    asTenant($asset, function () {
        app(AnswerQuestionService::class)->answer('show me the trial balance');

        $spy = app(AssistantModel::class);
        $passages = $spy::$seen;

        expect($passages)->not->toBeEmpty();

        // The trial balance passage is the REPORT'S OWN OUTPUT — its column headings — not the
        // screen guide's prose about what a trial balance is.
        $figures = collect($passages)->first(fn (array $p): bool => str_contains($p['body'], 'Debit'));

        expect($figures)->not->toBeNull('the report ran but its rows never reached the model');
    });
});

it('asks the PAGE whether it is a report, not the ranked kind', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));
    app()->instance(AssistantModel::class, figureSpy());

    // The defect this pins: `mergeDuplicateDestinations()` folds a report into its screen entry and
    // keeps the SCREEN's identity, so a page that is both — which is most of the catalogue —
    // arrived as kind `screen` and its figures were never fetched. The question "is this a report"
    // has one honest answer: the contract the page implements.
    asTenant($asset, function () {
        $answer = app(AnswerQuestionService::class)->answer('show me the trial balance');

        expect($answer['results'][0]['kind'])->toBe('screen');

        $spy = app(AssistantModel::class);
        expect(collect($spy::$seen)->contains(fn (array $p): bool => str_contains($p['body'], 'Debit')))
            ->toBeTrue('a merged report/screen entry must still fetch its figures');
    });
});

// ── B1b: the record somebody named ─────────────────────────────────────────────────────────────

it('quotes the named record\'s own figures', function () {
    $asset = makeAsset();
    $tenant = makeTenant(['name' => 'Qamaria Coffee']);
    makeLease(makeUnit($asset), $tenant);
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $summary = App\Support\Assistant\RecordSummary::find(['qamaria']);

        expect($summary)->not->toBeNull()
            ->and($summary['title'])->toContain('Qamaria')
            // The balance is DERIVED, not stored — read through the model's own method so it
            // cannot become a second answer to a question recomputeTotals() already answers.
            ->and($summary['body'])->toContain(__('admin.fields.outstanding_balance'));
    });
});

it('never quotes a field that is not on the allowlist', function () {
    $asset = makeAsset();
    $tenant = makeTenant(['name' => 'Qamaria Coffee', 'notes' => 'SECRET-INTERNAL-NOTE']);
    makeLease(makeUnit($asset), $tenant);
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $summary = App\Support\Assistant\RecordSummary::find(['qamaria']);

        // Handing back the row would hand back whatever the table happens to carry. The fields are
        // listed, and `notes` is not one of them.
        expect($summary['body'])->not->toContain('SECRET-INTERNAL-NOTE');
    });
});

it('never summarises a record from a register the reader cannot open', function () {
    $asset = makeAsset();
    $tenant = makeTenant(['name' => 'Qamaria Coffee']);
    makeLease(makeUnit($asset), $tenant);

    // The refusal: `technician` holds no tenant register.
    $this->actingAs(makeUser('technician'));
    asTenant($asset, fn () => expect(App\Support\Assistant\RecordSummary::find(['qamaria']))->toBeNull());

    // The control, in the same shape.
    auth()->forgetUser();
    $this->actingAs(makeUser('super_admin'));
    asTenant($asset, fn () => expect(App\Support\Assistant\RecordSummary::find(['qamaria']))->not->toBeNull());
});

it('never reaches a record in another property', function () {
    $mine = makeAsset();
    $theirs = makeAsset();
    makeLease(makeUnit($theirs), makeTenant(['name' => 'Qamaria Roasters']));
    $this->actingAs(makeUser('super_admin'));

    // Scope is inherited from the resource's own getEloquentQuery(), never re-implemented here.
    asTenant($mine, fn () => expect(App\Support\Assistant\RecordSummary::find(['roasters']))->toBeNull());
    asTenant($theirs, fn () => expect(App\Support\Assistant\RecordSummary::find(['roasters']))->not->toBeNull());
});

it('puts the record summary in front of the model', function () {
    $asset = makeAsset();
    $tenant = makeTenant(['name' => 'Qamaria Coffee']);
    makeLease(makeUnit($asset), $tenant);
    $this->actingAs(makeUser('super_admin'));
    app()->instance(AssistantModel::class, figureSpy());

    asTenant($asset, function () {
        app(AnswerQuestionService::class)->answer('what does Qamaria owe');

        $spy = app(AssistantModel::class);

        expect(collect($spy::$seen)->contains(fn (array $p): bool => str_contains($p['title'], 'Qamaria')))
            ->toBeTrue('the record was found but its figures never reached the model');
    });
});

// ── B1c: how many, and how do they split ───────────────────────────────────────────────────────

it('counts, and splits only by a column this system has classified', function () {
    $asset = makeAsset();
    foreach (range(1, 3) as $i) {
        makeUnit($asset);
    }
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $resource = App\Filament\Admin\Resources\Units\UnitResource::class;

        // A total when nothing names a column.
        $total = App\Support\Assistant\RecordCount::for($resource, ['how', 'many', 'units'], 'how many units');
        expect($total['body'])->toContain('3');

        // A split when the question names one — and `units.status` is registered in ValueSets,
        // which is the only reason it may be grouped by. There is no SQL here to write.
        $split = App\Support\Assistant\RecordCount::for($resource, ['how', 'many', 'units', 'status'], 'how many units status');
        expect($split['body'])->toContain(__('admin.statuses.unit.vacant'));

        // A word naming no registered column falls back to the total rather than inventing a
        // grouping — the whole point of taking the column from a registry.
        $unknown = App\Support\Assistant\RecordCount::for($resource, ['how', 'many', 'units', 'wibble'], 'how many units wibble');
        expect($unknown['body'])->not->toContain('—');
    });
});

it('renders the stored codes in the reader\'s own language', function () {
    $asset = makeAsset();
    makeUnit($asset);
    $this->actingAs(makeUser('super_admin'));
    app()->setLocale('ar');

    asTenant($asset, function () {
        $split = App\Support\Assistant\RecordCount::for(
            App\Filament\Admin\Resources\Units\UnitResource::class,
            ['كم', 'عدد', 'الوحدات', 'الحالة'],
            'كم عدد الوحدات حسب الحالة',
        );

        // The catalogue is keyed by the MODEL, singular — `admin.statuses.unit`, not `units` —
        // and getting it wrong is silent: "Vacant: 11" inside an Arabic sentence, the
        // half-translated shape this codebase keeps finding.
        expect($split['body'])->toMatch('/\p{Arabic}/u')
            ->and($split['body'])->not->toContain('vacant');
    });

    app()->setLocale('en');
});

it('will not count a register the reader may not open', function () {
    $asset = makeAsset();
    makeUnit($asset);

    $resource = App\Filament\Admin\Resources\Units\UnitResource::class;

    $this->actingAs(makeUser('marketing'));
    asTenant($asset, fn () => expect(App\Support\Assistant\RecordCount::for($resource, ['how', 'many', 'units'], 'how many units'))->toBeNull());

    auth()->forgetUser();
    $this->actingAs(makeUser('super_admin'));
    asTenant($asset, fn () => expect(App\Support\Assistant\RecordCount::for($resource, ['how', 'many', 'units'], 'how many units'))->not->toBeNull());
});

it('counts only the reader\'s own property', function () {
    $mine = makeAsset();
    $theirs = makeAsset();
    makeUnit($mine);
    foreach (range(1, 4) as $i) {
        makeUnit($theirs);
    }
    $this->actingAs(makeUser('super_admin'));

    // The count runs on the resource's own getEloquentQuery(), so it is the list page's query —
    // not a re-implementation that could forget the scope.
    asTenant($mine, function () {
        $body = App\Support\Assistant\RecordCount::for(
            App\Filament\Admin\Resources\Units\UnitResource::class, ['how', 'many', 'units'], 'how many units')['body'];

        expect($body)->toContain('1')->not->toContain('5');
    });
});

it('refuses to count a register it may not quote', function () {
    // The SAME allowlist that governs reading a record back. Counting rows of a register nobody may
    // quote is a smaller leak of the same kind — "how many employees" is a question about people.
    expect(App\Support\AssistantFields::isSummarisable(App\Models\Employee::class))->toBeFalse();

    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, fn () => expect(App\Support\Assistant\RecordCount::for(
        App\Filament\Admin\Resources\Employees\EmployeeResource::class, ['how', 'many', 'employees'], 'how many employees'))->toBeNull());
});

// ── B1d: the tool subtracts, never the model ───────────────────────────────────────────────────

it('computes the difference itself, from known figures', function () {
    $a = ['headers' => ['Line', 'Amount'], 'rows' => [['Revenue', '100000.00'], ['Expenses', '40000.00'], ['Old line', '500.00']], 'total' => 3, 'truncated' => false];
    $b = ['headers' => ['Line', 'Amount'], 'rows' => [['Revenue', '125000.00'], ['Expenses', '37500.50'], ['New line', '900.00']], 'total' => 3, 'truncated' => false];

    $lines = implode("\n", App\Support\Assistant\PeriodCompare::diff($a, $b, 2025, 2026));

    // The arithmetic, done in PHP from figures the report produced. A model shown two tables will
    // usually get this right and will eventually be confidently wrong about a number somebody acts
    // on — and a wrong delta reads as a result rather than an opinion.
    expect($lines)->toContain('+25,000.00')   // 125,000 − 100,000
        ->and($lines)->toContain('-2,499.50'); // 37,500.50 − 40,000

    // A row on one side only is NEW or GONE, never a change from zero: "revenue up 100%" and
    // "this line did not exist last year" are different statements and only one is true.
    expect($lines)->toContain('New line')->toContain('Old line')
        ->and($lines)->not->toContain('+900.00');
});

it('reads the two periods from the question, and invents no third', function () {
    $c = App\Support\Assistant\PeriodCompare::class;

    expect($c::years(['compare', '2025', '2026']))->toBe([2025, 2026])
        // One named year compares against the year before it.
        ->and($c::years(['revenue', '2026']))->toBe([2025, 2026])
        // Naming none compares this year with last, rather than guessing at a range.
        ->and($c::years(['compare', 'revenue']))->toBe([(int) now()->year - 1, (int) now()->year]);
});

it('will not compare a report that has no year to compare by', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    // Two identical runs presented as a trend is worse than no answer. The rent roll takes an
    // `asOf` date, not a year.
    asTenant($asset, fn () => expect(App\Support\Assistant\PeriodCompare::for(
        App\Filament\Admin\Pages\RentRoll::class, ['compare', 'rent', 'roll']))->toBeNull());
});

it('compares one report against itself, both sides scoped to the reader', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    asTenant($asset, function () {
        $result = App\Support\Assistant\PeriodCompare::for(
            App\Filament\Admin\Pages\IncomeStatement::class,
            ['compare', 'income', 'statement', '2026', '2025'],
        );

        // Both sides are the SAME report with a different year, so the columns are commensurable by
        // construction — comparing one report to another is a different and much harder feature.
        expect($result)->not->toBeNull()
            ->and($result['title'])->toContain('2025')
            ->and($result['title'])->toContain('2026');
    });

    // And a reader who cannot open the report gets no comparison of it either.
    auth()->forgetUser();
    $this->actingAs(makeUser('technician'));
    asTenant($asset, fn () => expect(App\Support\Assistant\PeriodCompare::for(
        App\Filament\Admin\Pages\IncomeStatement::class, ['compare', '2026', '2025']))->toBeNull());
});
