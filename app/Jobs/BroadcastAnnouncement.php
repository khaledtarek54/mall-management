<?php

namespace App\Jobs;

use App\Models\Announcement;
use App\Services\SendAnnouncementAction;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Runs an announcement's fan-out off the request thread — a property can have many
 * tenants, so composing in the admin panel shouldn't block on the broadcast.
 * tries=1: a broadcast must never auto-retry and re-spam tenants; the service's
 * sent_at guard is the idempotency backstop.
 */
class BroadcastAnnouncement implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public Announcement $announcement) {}

    public function handle(SendAnnouncementAction $action): void
    {
        $action->handle($this->announcement);
    }
}
