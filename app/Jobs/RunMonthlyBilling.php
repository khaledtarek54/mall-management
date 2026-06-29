<?php

namespace App\Jobs;

use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunMonthlyBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public ?string $period = null) {}

    /**
     * Serialise billing runs per period so a manually-dispatched run can't race
     * the scheduled one and double-bill (the existence check is not yet behind a
     * DB unique constraint — see docs/qa/HARDENING-BACKLOG.md).
     */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('monthly-billing:' . ($this->period ?? 'current')))->dontRelease()];
    }

    public function handle(MonthlyBillingService $service): array
    {
        $period = $this->period
            ? CarbonImmutable::createFromFormat('Y-m', $this->period)->startOfMonth()
            : CarbonImmutable::now()->startOfMonth();

        $stats = $service->runForPeriod($period);

        Log::info('Monthly billing run complete', $stats);

        return $stats;
    }
}
