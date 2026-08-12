<?php

namespace App\Jobs;

use App\Services\LateFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class ApplyLateFees implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public ?string $date = null) {}

    /**
     * Serialise late-fee runs per day, exactly as `RunMonthlyBilling` does per period.
     *
     * Without this the job was **re-entrant**: `$timeout = 600` against the queue's `retry_after`
     * of 90 meant any run over 90 seconds became reclaimable, and a second worker started the same
     * unbounded sweep over the whole arrears backlog while the first was still going. Correctness
     * survived — `applyTo()` locks each invoice and re-checks the full precondition inside the
     * transaction — but it was double the load and double the memory against AR, nightly at 04:00,
     * on the one dataset that never shrinks. `retry_after` was raised past every job timeout in the
     * same change; this guard is the belt to that braces, because a lock the operator can see is
     * worth more than a config value they have to remember.
     *
     * `dontRelease()`: a run that collides with one already going is DISCARDED, not requeued. The
     * sweep is idempotent and runs again tomorrow, so re-running it ten minutes later would only
     * repeat work the first run is doing.
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('late-fees:'.($this->date ?? 'today')))->dontRelease()];
    }

    public function handle(LateFeeService $service): array
    {
        $today = $this->date
            ? CarbonImmutable::parse($this->date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        return $service->runForToday($today);
    }
}
