<?php

namespace App\Notifications;

use App\Models\Lease;
use App\Notifications\Concerns\AlsoSendsByMail;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * This tenant's lodged post-dated cheques run out before their lease does.
 *
 * Mailed as well as belled, on the same test the rest of the system applies: does missing it cost
 * money on a clock? It does — a cheque batch is negotiated with the tenant, and the negotiation
 * has to happen before the last one is banked. After that the operator is chasing a tenant who
 * currently owes nothing, which is the worst possible moment to ask.
 *
 * The payload carries `covered_to`, so a batch lodged in response to this alert produces a
 * visibly DIFFERENT notification next time rather than what looks like a duplicate of one already
 * actioned.
 */
class ChequeCoverageEndingNotification extends Notification
{
    use AlsoSendsByMail;
    use Queueable;

    public function __construct(
        public Lease $lease,
        public string $coveredTo,
        public int $uncoveredMonths,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'cheque_coverage_ending',
            'lease_id' => $this->lease->id,
            'covered_to' => $this->coveredTo,
            'uncovered_months' => $this->uncoveredMonths,
            'title' => __('admin.notifications.cheque_coverage_ending_title'),
            'body' => __('admin.notifications.cheque_coverage_ending_body', [
                'tenant' => $this->lease->tenant?->name ?? '—',
                'unit' => $this->lease->unit?->code ?? '—',
                'lease' => $this->lease->reference ?? '—',
                'covered_to' => $this->coveredTo,
                'months' => $this->uncoveredMonths,
            ]),
            'icon' => 'heroicon-o-banknotes',
            // Warning, not danger: nothing is broken yet, and that is the point of sending it now.
            'color' => 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
