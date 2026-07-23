<?php

namespace App\Notifications;

use App\Models\VendorContract;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry for a vendor contract whose NOTICE deadline has arrived.
 *
 * Fired by `vendors:scan-contract-renewals`. The end date is the wrong thing to alert on — by then
 * the decision has already been made for you. What changes the message is `auto_renews`:
 *
 *   - auto-renewing → "serve notice by X or you are committed for another term at the old rate"
 *   - fixed term    → "this simply ends on X — renew it or line up a replacement contractor"
 *
 * Those demand opposite actions, so they must not share one vague wording.
 */
class VendorContractRenewalDueNotification extends Notification
{
    use Queueable;

    public function __construct(public VendorContract $contract) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $days = (int) ($this->contract->daysToNoticeDeadline() ?? 0);
        $autoRenews = (bool) $this->contract->auto_renews;
        $passed = $days < 0;

        $body = match (true) {
            $autoRenews && $passed => 'admin.notifications.contract_notice_missed_auto_body',
            $autoRenews => 'admin.notifications.contract_notice_due_auto_body',
            $passed => 'admin.notifications.contract_notice_missed_body',
            default => 'admin.notifications.contract_notice_due_body',
        };

        return [
            'type' => 'vendor_contract_notice',
            'vendor_contract_id' => $this->contract->id,
            'vendor_id' => $this->contract->vendor_id,
            'auto_renews' => $autoRenews,
            // The end date this alert is about — so re-signing is visibly a NEW alert, not a repeat.
            'end_date' => $this->contract->end_date?->toDateString(),
            'title' => __($autoRenews
                ? 'admin.notifications.contract_notice_due_auto_title'
                : 'admin.notifications.contract_notice_due_title'),
            'body' => __($body, [
                'contract' => $this->contract->name,
                'vendor' => $this->contract->vendor->name ?? '—',
                'deadline' => $this->contract->noticeDeadline()?->format('Y-m-d') ?? '—',
                'end' => $this->contract->end_date?->format('Y-m-d') ?? '—',
                'days' => abs($days),
            ]),
            'icon' => 'heroicon-o-calendar-days',
            'color' => $autoRenews || $passed ? 'danger' : 'warning',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
