<?php

use App\Support\ProjectedState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;

/**
 * A stored column that is a function of TODAY has a sweep, and the sweep is scheduled.
 *
 * **The gap this closes (pre-staging QA, F-04 / F-05).** `units.status` and `leases.status` are both
 * projections — the first from the leases currently holding the unit, the second from whether the
 * term has run out. Both are stored, because every list, filter, map and occupancy figure reads
 * them. And both go wrong **on a day when nothing happened**, which is the one failure mode no
 * write-triggered recomputation can catch.
 *
 * `Unit::recomputeStatus()` existed and was correct; it simply never ran on a schedule, so a
 * give-back effective 1 January left the column saying `occupied` from that day on. Nothing moved a
 * lease to `expired` at all — measured seven months after a term ended, the shop was still
 * un-relettable and its rent was still being escalated.
 *
 * The gate has four teeth, because the registry alone would have passed the original state: the
 * projector existing is not enough, the sweep existing is not enough (`recomputeStatus()` existed
 * all along), and a sweep that is not **scheduled** is exactly F-04.
 */
beforeEach(fn () => seedRoles());

it('registers the projections it knows about — a registry that swept nothing would pass forever', function () {
    $columns = collect(ProjectedState::PROJECTIONS)
        ->map(fn (array $p) => (new $p['model'])->getTable().'.'.$p['column']);

    expect($columns)->toContain('units.status')->toContain('leases.status');
});

it('keeps every projection pointing at a column and a projector that still exist', function () {
    $broken = [];

    foreach (ProjectedState::PROJECTIONS as $key => $projection) {
        $model = new $projection['model'];

        if (! Schema::hasColumn($model->getTable(), $projection['column'])) {
            $broken[] = "{$key}: {$model->getTable()}.{$projection['column']} no longer exists";
        }

        if (! method_exists($model, $projection['projector'])) {
            $broken[] = "{$key}: {$projection['model']}::{$projection['projector']}() no longer exists";
        }
    }

    expect($broken)->toBe([], "A projection registry pointing at nothing protects nothing:\n  ".implode("\n  ", $broken));
});

it('gives every projection a sweep that exists AND is scheduled', function () {
    $commands = collect(Artisan::all())->keys();
    $schedule = file_get_contents(base_path('routes/console.php'));

    $problems = [];

    foreach (ProjectedState::PROJECTIONS as $key => $projection) {
        $sweep = $projection['sweep'];

        if (! $commands->contains($sweep)) {
            $problems[] = "{$key}: [{$sweep}] is not a registered command";

            continue;
        }

        // The tooth that would have caught F-04. `recomputeStatus()` existed for the whole of
        // module 01's life; what did not exist was anything that CALLED it on a timer.
        if (! str_contains($schedule, "Schedule::command('{$sweep}')")) {
            $problems[] = "{$key}: [{$sweep}] exists but nothing schedules it — "
                .'a sweep nobody runs is the state this registry was written for';
        }
    }

    expect($problems)->toBe([], "Stale-by-tomorrow:\n  ".implode("\n  ", $problems));
});

it('keeps each sweep idempotent — a second run must change nothing', function () {
    // A sweep that keeps finding work on unchanged data is either non-deterministic or writing
    // where it should not, and either way it will churn the activity log nightly.
    $churning = [];

    foreach (ProjectedState::sweeps() as $sweep) {
        Artisan::call($sweep);          // first pass does whatever work there is
        Artisan::call($sweep);          // second pass must find none
        $second = Artisan::output();

        // Every number the sweep reports must be zero. Asserted by parsing rather than with
        // `toContain('0')`, which passes on "10 leases" — and Pest matchers take no message
        // argument, so a third one there becomes a second needle rather than an explanation.
        preg_match_all('/\b(\d+)\b/', $second, $matches);
        $counts = array_map('intval', $matches[1]);

        if (array_filter($counts) !== []) {
            $churning[] = "[{$sweep}] reported work on a second consecutive run: ".trim($second);
        }
    }

    expect($churning)->toBe([], implode("\n  ", $churning));
});

it('explains every column that looks like a projection and is not', function () {
    // The registry is only worth having if the exceptions are written down — an unexplained
    // absence and a considered one look identical from the outside.
    foreach (ProjectedState::NOT_PROJECTED as $column => $reason) {
        expect(mb_strlen($reason))->toBeGreaterThan(40,
            "[{$column}] is excluded with a reason too thin to review: {$reason}");
    }

    expect(ProjectedState::NOT_PROJECTED)->not->toBeEmpty();
});
