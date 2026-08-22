<?php

use App\Models\JournalEntry;
use App\Services\Reports\ReportCsvExporter;
use App\Support\JournalNarrative;
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
