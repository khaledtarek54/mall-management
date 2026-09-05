<?php

use App\Contracts\AssistantModel;
use App\Filament\Admin\Resources\Units\UnitResource;
use App\Models\Invoice;
use App\Services\Assistant\AnswerQuestionService;
use App\Support\Assistant\AssistantCorpus;
use App\Support\Assistant\RecordCount;
use App\Support\Assistant\RecordStates;
use App\Support\AssistantFields;
use App\Support\ValueSets;
use Database\Seeders\RolesPermissionsSeeder;
use Illuminate\Support\Facades\Lang;

/**
 * "WHAT IS THE PENDING INVOICES" — reported from the panel, and it found four defects.
 *
 * The question was answered with the DEFINITION of an invoice. Measuring the neighbours around it
 * was worse: three of them answered with a confident figure about a different question.
 *
 *   1. **A qualifier was silently dropped.** "How many invoices are unpaid" answered *There are 2
 *      Invoices in this property* — the total, on books whose two invoices were both PAID. The
 *      answer was zero.
 *   2. **A negation inverted the answer.** `not` and `no` are STOP WORDS, stripped before any of
 *      the ranking sees them, so "how many invoices are NOT paid" arrived as `[invoices, paid]` and
 *      answered *2 of 2 Invoices are Paid* — the opposite of the question, in figures, about money.
 *   3. **The compound value swallowed the exact one.** `partially_paid` tokenises to "partially" +
 *      "paid", so it took the word *paid* and won on registry order: "how many invoices are paid"
 *      answered *0 of 2 are Partially Paid*.
 *   4. **A frozen module answered.** "How many invoices by status" grouped by `eta_status` — ETA is
 *      in `Modules::FROZEN` and hidden from every other surface — and rendered *"By ETA status
 *      — : 2"*, a blank label. `eta_status` also has a value literally called `pending`, so the
 *      reported question was one step from being answered by a frozen module's column.
 *
 * Every one of them is a number an operator would have acted on. **The figures are asserted, never
 * the prose**: what the model writes varies, which rows were counted does not.
 */
beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    AssistantCorpus::flush();
});

afterEach(fn () => AssistantCorpus::flush());

/** Records the passages it was handed, so the figure can be read back. */
function countSpy(): AssistantModel
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

/** Two paid invoices and one still owed — so a dropped qualifier reads differently from an honest count. */
function booksWithOneUnpaidInvoice(): array
{
    $asset = makeAsset();
    $unit = makeUnit($asset);
    $lease = makeLease($unit, makeTenant());

    makeInvoice($lease, ['status' => 'paid']);
    makeInvoice($lease, ['status' => 'paid']);
    makeInvoice($lease, ['status' => 'overdue']);

    return [$asset, $lease];
}

function assistantFigureFor(string $question, $asset, string $locale = 'en'): string
{
    app()->setLocale($locale);

    $spy = countSpy();
    app()->instance(AssistantModel::class, $spy);

    return asTenant($asset, function () use ($question, $spy) {
        $spy::$seen = [];
        app(AnswerQuestionService::class)->answer($question);

        return collect($spy::$seen)->pluck('body')->implode(' ');
    });
}

it('answers the question that was reported, with a figure', function () {
    [$asset] = booksWithOneUnpaidInvoice();
    $this->actingAs(makeUser('super_admin'));

    // The whole complaint: this used to come back as prose about what an invoice is.
    expect(assistantFigureFor('what is the pending invoices', $asset))
        ->toContain('1 of 3');
});

it('counts only the rows the qualifier names', function (string $question, string $expected) {
    [$asset] = booksWithOneUnpaidInvoice();
    $this->actingAs(makeUser('super_admin'));

    expect(assistantFigureFor($question, $asset))->toContain($expected);
})->with([
    'unpaid' => ['how many invoices are unpaid', '1 of 3'],
    'outstanding' => ['how many invoices are outstanding', '1 of 3'],
    'paid' => ['how many invoices are paid', '2 of 3'],
    // The negation, which used to answer its own opposite.
    'not paid' => ['how many invoices are not paid', '1 of 3'],
    // The exact value must beat the compound that contains its word.
    'partially paid' => ['how many invoices are partially paid', '0 of 3'],
    'overdue' => ['how many invoices are overdue', '1 of 3'],
]);

it('does not invert a negated question in arabic either', function () {
    [$asset] = booksWithOneUnpaidInvoice();
    $this->actingAs(makeUser('super_admin'));

    // «غير مدفوعة» — "not paid". The phrase carries its own negation and must not be overridden by
    // the value «مدفوعة» sitting inside it.
    expect(assistantFigureFor('كم فاتورة غير مدفوعة', $asset, 'ar'))->toContain('1 من 3');
    expect(assistantFigureFor('كم فاتورة مدفوعة', $asset, 'ar'))->toContain('2 من 3');
});

it('never groups by a frozen module s column, and never renders a blank bucket', function () {
    [$asset] = booksWithOneUnpaidInvoice();
    $this->actingAs(makeUser('super_admin'));

    $body = assistantFigureFor('how many invoices by status', $asset);

    // `eta_status` is entirely null on these books, so grouping by it produced ": 3".
    expect($body)->not->toContain('ETA')
        ->and($body)->not->toMatch('/—\s*:/')
        ->and($body)->toContain('Paid: 2');
});

it('only ever groups or filters by a column it is allowed to quote', function () {
    // The registry relationship the fix rests on: one list governs quoting AND counting, so a
    // column nobody chose to expose can never be reached through the count.
    $quotable = AssistantFields::columnsFor(Invoice::class);

    expect($quotable)->toContain('status')
        ->and($quotable)->not->toContain('eta_status');
});

it('expands every state concept to values the column really holds', function () {
    // A concept is a SET of registered values and nothing else — so it can never invent a status or
    // reach a column nobody classified. One wrong guess was caught this way before it shipped:
    // credit notes have no `partially_applied`.
    expect(RecordStates::CONCEPTS)->not->toBeEmpty();

    foreach (RecordStates::CONCEPTS as $key => $concepts) {
        [$table, $column] = explode('.', $key, 2);
        $real = ValueSets::forTable($table)[$column] ?? [];

        expect($real)->not->toBeEmpty("{$key} is not a classified column");

        foreach ($concepts as $state => $concept) {
            expect(array_diff($concept['values'], $real))
                ->toBe([], "{$key}/{$state} names a value the column does not hold");

            expect(trans()->has("admin.assistant.states.{$state}"))->toBeTrue("no wording for {$state}");
            expect(Lang::has("admin.assistant.states.{$state}", 'ar', fallback: false))
                ->toBeTrue("no Arabic wording for {$state}");
        }
    }
});

it('will not fall back to a bare total when only a state brought it here', function () {
    // A named state makes the count reachable WITHOUT a counting verb — that is what lets "what is
    // the pending invoices" answer at all. The cost, found in review: the passage loop tries each
    // retrieved resource in turn and takes the first that answers, so a resource the state does not
    // apply to would hand back the register's TOTAL and stop — a figure about something else,
    // pinned to the top of the answer with the authority of a measurement.
    //
    // Asserted on the tool rather than through the service, because it has to be the SAME question
    // and resource either side of the flag: the only difference that may explain the two results is
    // the guard itself.
    [$asset] = booksWithOneUnpaidInvoice();
    $this->actingAs(makeUser('super_admin'));

    $units = UnitResource::class;
    $question = 'what is the pending invoices';
    $words = ['pending', 'invoices'];

    asTenant($asset, function () use ($units, $question, $words) {
        // Units have no "pending" state, so with a counting verb a total is a fair answer...
        expect(RecordCount::for($units, $words, $question))->not->toBeNull();

        // ...and with nothing but the state to go on, it is not an answer at all.
        expect(RecordCount::for($units, $words, $question, mustFilter: true))
            ->toBeNull();
    });
});

it('does not pin a figure to a question that was not asking for one', function (string $question) {
    // The vocabulary half of the same finding: `free` fired on "rent free period", `running` on
    // "running out of cheques", `let` on "let a parking bay".
    [$asset] = booksWithOneUnpaidInvoice();
    $this->actingAs(makeUser('super_admin'));

    expect(assistantFigureFor($question, $asset))->not->toContain('There are 3 Invoices');
})->with([
    'a rent free period' => ['record a rent free period'],
    'letting a bay' => ['let a parking bay'],
    'running out of cheques' => ['which tenants are running out of cheques'],
]);

it('keeps ubiquitous words out of the state vocabulary', function () {
    // The rule `admin.assistant.synonyms` already obeys, one level down — and this registry broke
    // it on its first pass. `free` fired on "rent free period", `running` and `current` on "running
    // out of cheques" and "the current period", `let` on "let a parking bay".
    $banned = ['free', 'current', 'running', 'let', 'taken', 'gone', 'remaining', 'no', 'not'];

    foreach (RecordStates::CONCEPTS as $key => $concepts) {
        foreach ($concepts as $state => $concept) {
            foreach ($concept['words'] as $phrase) {
                // Only a phrase of ONE word is a hazard: a multi-word entry has to be typed whole,
                // so «غير مدفوعة» may legitimately contain a word that is useless on its own.
                $tokens = AssistantCorpus::tokenise($phrase);

                if (count($tokens) !== 1) {
                    continue;
                }

                expect(in_array($tokens[0], $banned, true))
                    ->toBeFalse("{$key}/{$state} lists the ubiquitous word '{$tokens[0]}'");
            }
        }
    }
});
