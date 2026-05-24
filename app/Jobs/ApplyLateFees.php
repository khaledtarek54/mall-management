<?php

namespace App\Jobs;

use App\Services\LateFeeService;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ApplyLateFees implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 600;
    public int $tries = 1;

    public function __construct(public ?string $date = null) {}

    public function handle(LateFeeService $service): array
    {
        $today = $this->date
            ? CarbonImmutable::parse($this->date)->startOfDay()
            : CarbonImmutable::now()->startOfDay();

        return $service->runForToday($today);
    }
}
