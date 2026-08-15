<?php

namespace App\Notifications;

use App\Models\ServicePlan;
use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * A preventive-maintenance plan has stopped generating work orders.
 *
 * `GeneratePreventiveWorkOrdersService` contains each plan's failure so one bad row cannot halt the
 * whole portfolio, and rolls the cycle back whole so a failed attempt leaves nothing half-made.
 * Both are right; together they mean the plan retries the same cycle every night, forever. The only
 * trace was a `Log::warning` and a non-zero exit from a cron job that has no `onFailure` hook
 * anywhere in `routes/console.php` — so **the statutory lift round stops and nobody is told**.
 *
 * Mail as well as the bell: the gap between "the plan stopped" and "somebody noticed" is measured in
 * missed inspections, and an inspection nobody can prove happened is what an insurer asks about
 * after an incident.
 *
 * Fired once, on the transition into failure — a nightly repeat of a known problem is a message
 * people filter.
 */
class PreventiveGenerationFailedNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(public ServicePlan $plan, public string $reason) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'preventive_generation_failed',
            'service_plan_id' => $this->plan->id,
            'title' => __('admin.notifications.ppm_generation_failed_title'),
            'body' => __('admin.notifications.ppm_generation_failed_body', [
                'plan' => (string) $this->plan->title,
                'due' => optional($this->plan->next_due_date)->toDateString() ?? '—',
                'reason' => $this->reason,
            ]),
            'icon' => 'heroicon-o-exclamation-triangle',
            'color' => 'danger',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
