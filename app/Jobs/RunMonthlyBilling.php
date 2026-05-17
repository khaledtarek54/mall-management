<?php

namespace App\Jobs;

use App\Services\MonthlyBillingService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RunMonthlyBilling implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public ?string $period = null) {}

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
