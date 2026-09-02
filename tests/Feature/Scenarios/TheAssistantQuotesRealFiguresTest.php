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
