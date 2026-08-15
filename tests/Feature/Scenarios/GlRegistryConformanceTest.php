<?php

use App\Console\Commands\SyncLedgerCommand;
use App\Services\Accounting\Journalizers\Journalizer;
use App\Services\Accounting\LedgerPoster;
use App\Support\LedgerRealtimeSync;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * The self-enforcing gate on the general-ledger registry — the money-critical sibling of
 * PropertyIsolationConformanceTest and AdminSmokeManifestConformanceTest.
 *
 * WHY THIS EXISTS. "Which models post to the GL" was once hand-copied into five places:
 * LedgerPoster's journalizer match, LedgerRealtimeSync::SOURCES, its SOURCE_DATE_COLUMNS,
 * SyncLedgerCommand's sweep, and BooksReconciliationService's drift check. They drifted:
 * SlaPenalty had a correct, tested journalizer but appeared in NONE of the others,
 * so applying an SLA penalty cut the vendor bill's AP balance and posted no journal entry —
 * and neither the sweep nor `billing:reconcile` could see it, because both walked a list
 * the penalty wasn't on. Every dispatch path now derives from LedgerPoster::JOURNALIZERS;
 * these tests hold the line on the parts that still can't be derived.
 *
 * If you are here because a test failed: you added a journalizer. Add its date column (and
 * an eager load if its payload walks a relation). Do NOT weaken the assertion.
 */
it('gives every journalizer an entry-date column for the close gate', function () {
    // PeriodService uses SOURCE_DATE_COLUMNS to find documents DATED in a period being
    // closed — including never-posted ones. A source missing here can be stranded by a
    // close: its future post would land in a locked period and fail forever.
    $missing = array_diff(LedgerPoster::sources(), array_keys(LedgerRealtimeSync::SOURCE_DATE_COLUMNS));

    expect($missing)->toBe([], 'Journalized but no entry_date column — add it to LedgerRealtimeSync::SOURCE_DATE_COLUMNS: '.implode(', ', $missing));
});

it('does not declare an entry-date column for anything that cannot post', function () {
    $orphans = array_diff(array_keys(LedgerRealtimeSync::SOURCE_DATE_COLUMNS), LedgerPoster::sources());

    expect($orphans)->toBe([], 'Date column for a model with no journalizer: '.implode(', ', $orphans));
});

it('points every entry-date column at a real column on its source table', function () {
    foreach (LedgerRealtimeSync::SOURCE_DATE_COLUMNS as $class => $column) {
        /** @var Model $model */
        $model = new $class;

        expect($model->getConnection()->getSchemaBuilder()->hasColumn($model->getTable(), $column))
            ->toBeTrue("{$class}: SOURCE_DATE_COLUMNS points at '{$column}', which does not exist on '{$model->getTable()}'.");
    }
});

it('registers every journalizer that exists on disk', function () {
    // An orphan journalizer — a class that builds correct entries but is wired to nothing —
    // is the exact shape of the SlaPenalty bug, one step earlier. It looks done in a
    // code review and posts nothing in production.
    $onDisk = collect(glob(app_path('Services/Accounting/Journalizers/*Journalizer.php')))
        ->map(fn ($f) => 'App\\Services\\Accounting\\Journalizers\\'.basename($f, '.php'))
        ->reject(fn ($c) => $c === Journalizer::class)
        ->values();

    $orphans = $onDisk->diff(array_values(LedgerPoster::JOURNALIZERS));

    expect($orphans->all())->toBe([], 'Journalizer class registered nowhere — it will never post: '.$orphans->implode(', '));
});

it('registers a real journalizer class for every source', function () {
    foreach (LedgerPoster::JOURNALIZERS as $source => $journalizer) {
        expect(class_exists($source))->toBeTrue("Journalized source {$source} does not exist.")
            ->and(is_subclass_of($source, Model::class))->toBeTrue("{$source} is not an Eloquent model.")
            ->and(class_exists($journalizer))->toBeTrue("Journalizer {$journalizer} does not exist.")
            ->and(is_subclass_of($journalizer, Journalizer::class))->toBeTrue("{$journalizer} does not implement the Journalizer contract.");
    }
});

it('derives the ledger sweep from the registry rather than a hand-listed copy', function () {
    // The sweep is the self-healing backstop: a source it never visits can never be
    // repaired, so a missed real-time post becomes permanent. It must walk
    // LedgerPoster::sources() — if someone re-introduces a hand-listed sweep, a new
    // journalizer would silently stop being self-healed, which is the original bug.
    $source = file_get_contents((new ReflectionClass(SyncLedgerCommand::class))->getFileName());

    expect($source)->toContain('LedgerPoster::sources()')
        ->and($source)->toContain('LedgerRealtimeSync::SOURCE_DATE_COLUMNS');
});

it('only eager-loads relations that exist on their source', function () {
    /** @var array<class-string, array<int, string>> $eager */
    $eager = (array) (new ReflectionClass(SyncLedgerCommand::class))->getConstant('EAGER');

    foreach ($eager as $class => $relations) {
        expect(in_array($class, LedgerPoster::sources(), true))
            ->toBeTrue("EAGER lists {$class}, which has no journalizer.");

        foreach ($relations as $relation) {
            expect(method_exists($class, $relation))
                ->toBeTrue("{$class}: EAGER declares relation '{$relation}', which does not exist.");
        }
    }
});

it('registers real-time hooks for every source, and restore only where restoring is possible', function () {
    LedgerRealtimeSync::register();

    foreach (LedgerPoster::sources() as $source) {
        expect($source::getEventDispatcher()->hasListeners('eloquent.saved: '.$source))
            ->toBeTrue("{$source}: no real-time saved hook — its entry would wait for the daily sweep.")
            ->and($source::getEventDispatcher()->hasListeners('eloquent.deleted: '.$source))
            ->toBeTrue("{$source}: no real-time deleted hook — its entry would not be voided.");

        if (in_array(SoftDeletes::class, class_uses_recursive($source), true)) {
            expect($source::getEventDispatcher()->hasListeners('eloquent.restored: '.$source))
                ->toBeTrue("{$source} soft-deletes but has no restored hook — a restore would not re-post.");
        }
    }
});
