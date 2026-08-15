<?php

use App\Models\Concerns\GuardsPostingDate;
use App\Support\LedgerRealtimeSync;
use App\Support\PostingDateGuards;
use Illuminate\Database\Eloquent\Model;

/**
 * The self-enforcing gate on closed-period guards — sibling of GlRegistryConformanceTest.
 *
 * WHY THIS EXISTS. "An operator-typed date that becomes a GL entry_date must be refused when
 * its period is closed" was fixed **six separate times**, one module at a time, each time as
 * if it were a local bug: custody settlement and advance repayment (F-93/F-89), vendor bills,
 * stock movements, procurement, PDC, then — in the 2026-07-29 sweep — fixed-asset disposal,
 * acquisition and depreciation, and the GRANT halves of custody and advances that the very
 * first fix had walked straight past.
 *
 * The failure is always the same and always silent: the row commits, the operator sees a
 * success toast, and the journal entry is refused inside the best-effort SyncDocumentToLedger
 * job, which logs rather than retries. Business state moves; the books don't.
 *
 * Fixing it a seventh time by hand is not a plan. Every GL source already has to declare its
 * entry-date column in LedgerRealtimeSync::SOURCE_DATE_COLUMNS (GlRegistryConformanceTest
 * keeps that list complete against LedgerPoster::JOURNALIZERS), so this gate hangs off that
 * same list: **every source must also declare where that date is guarded, or why it needs no
 * guard.** A new money source cannot ship without someone answering the question.
 *
 * If you are here because a test failed: add your source to PostingDateGuards. Do not
 * weaken the assertion — the whole value is that the answer is forced.
 */
it('makes every GL source declare where its posting date is guarded', function () {
    $undeclared = array_diff(
        array_keys(LedgerRealtimeSync::SOURCE_DATE_COLUMNS),
        array_keys(PostingDateGuards::guards()),
    );

    expect($undeclared)->toBe([], implode("\n", [
        'These post to the GL from a date nobody has classified: '.implode(', ', $undeclared),
        'Add each to PostingDateGuards — either the class that runs PostingDate::assertOpen',
        'on it, or a `system:` reason explaining why the date can never be operator-typed.',
    ]));
});

it('does not declare a guard for anything that cannot post', function () {
    // A stale entry is worse than none: it reads as coverage for a source that no longer exists.
    $orphans = array_diff(
        array_keys(PostingDateGuards::guards()),
        array_keys(LedgerRealtimeSync::SOURCE_DATE_COLUMNS),
    );

    expect($orphans)->toBe([], 'Guard declared for a model with no journalizer: '.implode(', ', $orphans));
});

it('points every declared guard at a class that actually runs the check', function () {
    // Catches the declaration going stale — someone refactors the guard out of the service and
    // the registry keeps claiming it is covered. The registry has to track the code, not the
    // intent at the time it was written.
    foreach (PostingDateGuards::guards() as $source => $guard) {
        if (PostingDateGuards::isSystemDated($guard)) {
            continue;
        }

        expect(class_exists($guard))->toBeTrue("{$source}: declared guard {$guard} does not exist.");

        $file = (new ReflectionClass($guard))->getFileName();
        $body = (string) file_get_contents($file);

        // Any PostingDate::assert* call counts — assertNotFuture calls assertOpen internally,
        // and pinning the exact method name made this gate fail on a service that WAS correctly
        // guarded. The trait carries the call for model-guarded sources, whose model only names
        // the column, so accept that too.
        $runsCheck = str_contains($body, 'PostingDate::assert')
            || str_contains($body, 'GuardsPostingDate');

        expect($runsCheck)->toBeTrue(
            "{$source}: {$guard} is declared as its posting-date guard but never consults ".
            'PostingDate (nor uses GuardsPostingDate).'
        );
    }
});

it('keeps a model-level guard pointed at the same column the ledger dates from', function () {
    // The one way a model guard can be quietly wrong: it guards a column the journalizer does
    // not date from, so it refuses the wrong thing and permits the real one.
    foreach (LedgerRealtimeSync::SOURCE_DATE_COLUMNS as $class => $column) {
        if (! in_array(GuardsPostingDate::class, class_uses_recursive($class), true)) {
            continue;
        }

        expect($class::postingDateColumn())->toBe(
            $column,
            "{$class} guards '{$class::postingDateColumn()}' but the ledger dates its entry from '{$column}'."
        );
    }
});

it('never marks a date system-dated while a form lets an operator type it', function () {
    // The exemption that rots. `system:` means "no human picks this date, so there is nothing
    // to guard" — the moment someone adds a DatePicker for it, that is false, and the source
    // silently loses its protection with no test failing anywhere else.
    $forms = collect(
        array_merge(
            glob(app_path('Filament/**/*.php'), GLOB_BRACE) ?: [],
            (function () {
                $found = [];
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path('Filament')));
                foreach ($it as $f) {
                    if ($f->isFile() && $f->getExtension() === 'php') {
                        $found[] = $f->getPathname();
                    }
                }

                return $found;
            })(),
        )
    )->unique()->map(fn ($f) => (string) file_get_contents($f))->implode("\n");

    foreach (PostingDateGuards::guards() as $source => $guard) {
        if (! PostingDateGuards::isSystemDated($guard)) {
            continue;
        }

        $column = LedgerRealtimeSync::SOURCE_DATE_COLUMNS[$source];

        expect($forms)->not->toContain(
            "DatePicker::make('{$column}')",
            "{$source} is marked system-dated ({$guard}), but a form offers a DatePicker for '{$column}'. ".
            'It is now operator-typed and needs a real guard.'
        );
    }
});

it('actually refuses a closed period on every model-guarded source', function () {
    // Behavioural, not structural: the four gates above all pass if the trait is wired up and
    // does nothing. This proves the refusal happens.
    $guarded = collect(LedgerRealtimeSync::SOURCE_DATE_COLUMNS)
        ->keys()
        ->filter(fn ($class) => in_array(GuardsPostingDate::class, class_uses_recursive($class), true));

    expect($guarded)->not->toBeEmpty('No model-guarded sources found — has the trait been removed?');

    foreach ($guarded as $class) {
        /** @var Model $model */
        $model = new $class;
        $column = $class::postingDateColumn();

        expect(method_exists($model, 'bootGuardsPostingDate'))->toBeTrue(
            "{$class} declares postingDateColumn() but the trait's boot hook is missing — the guard never runs."
        );

        expect($column)->not->toBeEmpty("{$class}::postingDateColumn() is empty.");
    }
});
