<?php

use App\Models\JournalEntry;
use App\Services\Accounting\FiscalCalendar;
use App\Services\Accounting\LedgerPoster;
use App\Services\Reports\ReportCsvExporter;
use App\Support\JournalNarrative;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Lang;

/**
 * EG-36, finding S-12 — a journal entry's narrative becomes a KEY resolved at read time.
 *
 * Twenty-four journalizers wrote Arabic and English prose literals into `description_ar` /
 * `description_en` at post time, which contradicts this project's own rule for the activity log:
 * **it stores DATA, never PROSE.** The consequences are the ones that rule exists to prevent — a
 * wording fix needs a deploy, it never reaches a row already posted, and a third language means
 * re-posting history.
 *
 * The stored prose STAYS and is still written, as a snapshot and a floor. Every row posted before
 * today has prose and no key, a manual entry is prose the operator typed, and a read site nobody
 * converted degrades to today's wording rather than to a blank cell — on a general ledger an empty
 * description is indistinguishable from an entry nobody described.
 */
it('reads the key, not the prose, when an entry carries one', function () {
    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'entry_date' => now(),
        'status' => 'draft',
        'is_manual' => true,
        // Deliberately DIFFERENT wording, so the assertion can only pass if the key won.
        'description_en' => 'stale prose',
        'description_ar' => 'نص قديم',
        'description_key' => 'invoice.posted',
        'description_data' => ['number' => 'INV-0001'],
    ]);

    expect($entry->displayDescription())->toBe('Invoice INV-0001');

    App::setLocale('ar');
    expect($entry->fresh()->displayDescription())->toBe('فاتورة INV-0001');
});

it('keeps reading an entry posted before keys existed', function () {
    // The floor, and the whole safety case: a ledger is evidence and history is never rewritten.
    App::setLocale('en');

    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'entry_date' => now(),
        'status' => 'draft',
        'is_manual' => true,
        'description_en' => 'Invoice INV-0009',
        'description_ar' => 'فاتورة INV-0009',
    ]);

    expect($entry->displayDescription())->toBe('Invoice INV-0009');

    App::setLocale('ar');
    expect($entry->fresh()->displayDescription())->toBe('فاتورة INV-0009');
});

it('falls back to the other language rather than showing nothing', function () {
    App::setLocale('ar');

    $entry = JournalEntry::create([
        'number' => 'JE-'.uniqid(),
        'entry_date' => now(),
        'status' => 'draft',
        'is_manual' => true,
        'description_en' => 'Opening balance',
        'description_ar' => null,
    ]);

    // A ledger line with no description reads as an entry nobody described, which is worse than
    // one in the wrong language.
    expect($entry->displayDescription())->toBe('Opening balance');
});

it('ignores a key nobody registered instead of printing it on a statement', function () {
    // `invoice.postd` rendered raw on a document an auditor reads is the failure mode; falling back
    // to the prose is the safe direction.
    expect(JournalNarrative::resolve('invoice.postd', [], 'Invoice INV-2', 'فاتورة INV-2'))
        ->toBe('Invoice INV-2');
});

it('prints an em dash for a placeholder with no value', function () {
    // Not `:number` left showing, which reads as a broken template on a financial statement.
    expect(JournalNarrative::resolve('invoice.posted', ['number' => null], null, null))
        ->toBe('Invoice —');
});

it('has both languages for every registered narrative, with no placeholder left over', function () {
    $missing = [];
    $unresolved = [];

    foreach (JournalNarrative::KEYS as $key => $placeholders) {
        $langKey = JournalNarrative::LANG_PREFIX.$key;

        foreach (['en', 'ar'] as $locale) {
            // `fallback: false` — Lang::has() falls back to English by default, so the obvious
            // check only ever catches a key missing from BOTH. The trap ActivityVocabulary records.
            if (! Lang::has($langKey, $locale, fallback: false)) {
                $missing[] = "{$key} [{$locale}]";

                continue;
            }

            $rendered = trans($langKey, array_fill_keys($placeholders, 'X'), $locale);

            if (str_contains($rendered, ':')) {
                $unresolved[] = "{$key} [{$locale}] → {$rendered}";
            }
        }
    }

    expect(implode(', ', $missing))->toBe('')
        ->and(implode(', ', $unresolved))->toBe('');

    // The sweep must have found something — a registry that quietly emptied would pass both loops.
    expect(count(JournalNarrative::KEYS))->toBeGreaterThanOrEqual(24);
});

it('leaves no journalizer writing prose without a key', function () {
    // The call-site half. A journalizer that keeps only prose is one whose entries a wording fix
    // can never reach, and it would look exactly like the converted ones in review.
    $offenders = [];

    foreach (glob(app_path('Services/Accounting/Journalizers/*.php')) as $file) {
        $source = (string) file_get_contents($file);

        if (str_contains($source, "'description_en' =>") && ! str_contains($source, "'description_key' =>")) {
            $offenders[] = basename($file, '.php');
        }
    }

    expect(implode(', ', $offenders))->toBe('');
});

it('persists the key onto the ROW a real posting writes, not just into the payload', function () {
    // The half that was missing, and it hid a total failure of the feature.
    //
    // The case above greps the journalizer SOURCE FILES for `'description_key' =>`, which proves
    // they EMIT a key. It cannot see what happens next: `JournalPostingService` — the one place a
    // `journal_entries` row is written — copied `description_en`/`description_ar` out of the
    // payload and dropped the key and its data on the floor. So all 24 journalizers were correct,
    // the resolver was correct, the lang files were correct, and every entry ever posted carried
    // prose and no key. Measured on a freshly seeded demo before the fix: **0 of 699 keyed**.
    //
    // A gate reporting a weaker property than it names is this codebase's most repeated defect, and
    // this one was in the gate written to prevent exactly that. So this drives a REAL document
    // through the REAL posting path and reads the row back out of the database.
    // The chart and its role mappings, because posting resolves real accounts.
    test()->seed(ChartOfAccountsSeeder::class);
    test()->seed(AccountMappingSeeder::class);
    app(FiscalCalendar::class)->ensureYear((int) now()->year);

    $lease = makeLease(makeUnit(makeAsset()), null, ['status' => 'active']);
    // Totals left to the helper: forcing them desynchronises the header from the lines and the
    // posting refuses as unbalanced, which would be the fixture failing rather than the feature.
    $invoice = makeInvoice($lease, ['status' => 'issued']);

    app(LedgerPoster::class)->sync($invoice->fresh());

    $entry = JournalEntry::query()
        ->where('source_type', $invoice->getMorphClass())
        ->where('source_id', $invoice->getKey())
        ->where('status', 'posted')
        ->first();

    expect($entry)->not->toBeNull('The invoice did not post at all — this case proves nothing.');

    expect($entry->description_key)->toBe('invoice.posted')
        ->and($entry->description_data)->toBeArray()
        // …and the stored data actually feeds the narrative, rather than being an empty array that
        // renders an em dash on a financial statement.
        ->and($entry->displayDescription())->toContain((string) $invoice->number);
});

it('resolves the narrative in the general-ledger CSV, not only on the screen', function () {
    // Found in review, after the commit. The exporter read the prose columns straight out of the
    // statement array, so the moment a narrative's wording changed the CSV and the general ledger
    // it was exported FROM would disagree about the same line — two truths about one entry, in the
    // feature whose entire purpose is to have one.
    App::setLocale('en');

    $csv = app(ReportCsvExporter::class)->generalLedger([
        'opening' => 0.0,
        'closing' => 0.0,
        'lines' => [[
            'entry_date' => '2026-03-01',
            'entry_number' => 'JE-1',
            'description_en' => 'stale prose',
            'description_ar' => 'نص قديم',
            'description_key' => 'invoice.posted',
            'description_data' => ['number' => 'INV-0001'],
            'debit' => 100.0,
            'credit' => 0.0,
            'running_balance' => 100.0,
        ]],
    ]);

    expect($csv['rows'][1][2])->toBe('Invoice INV-0001');
});

it('still exports the prose for a line posted before keys existed', function () {
    App::setLocale('en');

    $csv = app(ReportCsvExporter::class)->generalLedger([
        'opening' => 0.0,
        'closing' => 0.0,
        'lines' => [[
            'entry_date' => '2026-03-01',
            'entry_number' => 'JE-2',
            'description_en' => 'Opening balance',
            'description_ar' => null,
            'debit' => 0.0,
            'credit' => 50.0,
            'running_balance' => -50.0,
        ]],
    ]);

    expect($csv['rows'][1][2])->toBe('Opening balance');
});
