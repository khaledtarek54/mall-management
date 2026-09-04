<?php

use App\Models\JournalEntry;
use App\Models\MarketingBudget;
use App\Models\MarketingSpend;
use App\Services\Accounting\AccountResolver;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\JournalPostingService;
use App\Services\Accounting\LedgerPoster;
use App\Support\JournalNarrative;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\App;

/**
 * SW-150 — A REVERSAL SAYS WHY, AND IT SAYS IT IN THE READER'S LANGUAGE.
 *
 * EG-36 made a journal narrative a KEY resolved at read time, on the rule this project states for
 * the activity log: **a row stores DATA, never PROSE.** All 24 journalizers were converted and the
 * one place that writes an entry NOBODY journalizes was not — `JournalPostingService::void()`,
 * which built its narrative by concatenation:
 *
 *     'قيد عكسي للقيد '.$entry->number.($reason ? ' — '.$reason : '')
 *
 * and every machine `$reason` reaching it is an English sentence. So an Arabic accountant reading
 * the general ledger got **'قيد عكسي للقيد JE-0519 — Superseded by an updated document.'** — one
 * line in two languages, on the register an auditor reads, with no wording fix able to reach a row
 * already posted, and the same mixed sentence folded into the search blob in both languages.
 *
 * It is not a rare shape either: `LedgerPoster::sync()` voids and re-posts on EVERY re-derive, so
 * that sentence is the normal operating mode of a derived ledger.
 *
 * **The operator's own words still win, and are never translated** — the rule `LeaseEventNarrative`
 * states. What is a key is the SENTENCE AROUND them, so the wrapper reads in the reader's language
 * while what a person typed survives verbatim.
 */
beforeEach(function () {
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->format('Y'));

    $this->accounts = app(AccountResolver::class);
});

/** A balanced posted entry, so there is something to reverse. */
function postAReversibleEntry(float $amount = 1000.0): JournalEntry
{
    $accounts = test()->accounts;

    return app(JournalPostingService::class)->post([
        'entry_date' => now()->toDateString(),
        'lines' => [
            ['ledger_account_id' => $accounts->id('bank'), 'debit' => $amount, 'credit' => 0],
            ['ledger_account_id' => $accounts->id('accounts_receivable'), 'debit' => 0, 'credit' => $amount],
        ],
    ]);
}

it('words a reversal nobody explained in each language', function () {
    $entry = postAReversibleEntry();

    $reversal = app(JournalPostingService::class)->void($entry);

    expect($reversal->description_key)->toBe('reversal.posted')
        ->and($reversal->description_data)->toBe(['number' => $entry->number]);

    App::setLocale('en');
    expect($reversal->displayDescription())->toBe('Reversal of '.$entry->number);

    App::setLocale('ar');
    expect($reversal->fresh()->displayDescription())->toBe('قيد عكسي للقيد '.$entry->number);
});

it("keeps the operator's own words and translates only the sentence around them", function () {
    $entry = postAReversibleEntry();

    $reversal = app(JournalPostingService::class)->void($entry, 'Keyed against the wrong tenant');

    expect($reversal->description_key)->toBe('reversal.reason');

    App::setLocale('ar');

    // Their words, verbatim — a reason a person typed is evidence, not a string to translate.
    expect($reversal->displayDescription())
        ->toContain('Keyed against the wrong tenant')
        // …inside an Arabic sentence, which is the half that was already right and must stay right.
        ->toStartWith('قيد عكسي للقيد '.$entry->number);
});

it('says in Arabic why the LEDGER itself reversed an entry', function () {
    // The defect exactly as reported, through the real path: `LedgerPoster::sync()` voids and
    // re-posts whenever a document no longer matches its entry, and its reason was a hardcoded
    // English sentence dropped into an Arabic column.
    App::setLocale('en');

    $asset = makeAsset(['code' => 'REV1']);
    $budget = MarketingBudget::forPeriod($asset->id, (int) now()->format('Y'));

    $spend = MarketingSpend::create([
        'marketing_budget_id' => $budget->id,
        'description' => 'Mall dressing',
        'amount' => 40000,
        'spent_on' => now()->toDateString(),
        'paid_from' => 'bank',
    ]);

    app(LedgerPoster::class)->sync($spend->fresh());

    $original = JournalEntry::query()
        ->where('source_type', $spend->getMorphClass())
        ->where('source_id', $spend->id)
        ->whereNull('voided_at')
        ->firstOrFail();

    // Restate it — the ordinary correction, and the one this project deliberately leaves open on a
    // marketing spend (see MarketingSpendStaysDerivedTest).
    $spend->update(['amount' => 25000]);
    app(LedgerPoster::class)->sync($spend->fresh());

    $reversal = JournalEntry::query()->where('reversal_of_id', $original->id)->firstOrFail();

    expect($reversal->description_key)->toBe('reversal.superseded');

    App::setLocale('ar');

    // The whole complaint in one assertion: the Arabic narrative must be Arabic. The entry number
    // is Latin by construction and is the only thing allowed to be.
    $arabic = str_replace((string) $original->number, '', $reversal->displayDescription());

    expect(preg_match('/[A-Za-z]/', $arabic))->toBe(0, "The Arabic reversal narrative still carries English prose: {$reversal->displayDescription()}");
});

it('registers every reversal reason key a caller passes', function () {
    // DERIVED from the call sites, not from a list beside the registry: a key with no translation
    // renders raw on a financial statement, and `JournalNarrative` deliberately returns null for a
    // key it does not know — which would silently fall back to the stored prose and re-create the
    // bug while looking fixed.
    $keys = [];

    foreach (allPhpFilesUnderApp() as $source) {
        preg_match_all("/reasonKey:\s*'([^']+)'/", $source, $m);
        $keys = array_merge($keys, $m[1]);
    }

    expect($keys)->not->toBeEmpty('No reversal reason keys found — this sweep has stopped collecting.');

    foreach (array_unique($keys) as $key) {
        expect(JournalNarrative::KEYS)->toHaveKey($key);
    }
});

it('leaves nobody handing void() an English sentence', function () {
    // The class, not the instance. Three machine call sites passed prose and each looked fine in
    // its own file; the next one would too. A `$reason` is for words a PERSON typed — anything a
    // programmer writes is a key.
    $offenders = [];
    $callSites = 0;

    foreach (allPhpFilesUnderApp() as $path => $source) {
        if (! str_contains($source, 'JournalPostingService')) {
            continue;
        }

        $callSites += preg_match_all('/->void\(/', $source);

        // The second POSITIONAL argument opening with a quote. A named `reasonKey:`/`reasonData:`
        // does not match, and neither does a variable — which is what an operator's typed reason
        // always is.
        if (preg_match_all('/->void\([^,()]*,\s*["\']/', $source, $m)) {
            $offenders[] = str_replace(base_path().'/', '', $path).' ×'.count($m[0]);
        }
    }

    expect($callSites)->toBeGreaterThanOrEqual(4, 'The sweep found fewer void() call sites than exist — it has stopped collecting.');

    expect($offenders)->toBe([], implode("\n", [
        'These pass PROSE to JournalPostingService::void(), so the reversal it posts can only ever',
        'be read in the language it was written in. Pass a JournalNarrative key instead:',
        '    ->void($entry, reasonKey: \'reversal.superseded\')',
        '',
        ...$offenders,
    ]));
});

/**
 * Every PHP file under app/, keyed by path.
 *
 * @return array<string, string>
 */
function allPhpFilesUnderApp(): array
{
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path())) as $file) {
        if (! $file->isDir() && $file->getExtension() === 'php') {
            $files[$file->getPathname()] = (string) file_get_contents($file->getPathname());
        }
    }

    return $files;
}
