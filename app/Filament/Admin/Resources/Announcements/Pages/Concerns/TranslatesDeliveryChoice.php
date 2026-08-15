<?php

namespace App\Filament\Admin\Resources\Announcements\Pages\Concerns;

use App\Models\Announcement;
use App\Services\SendAnnouncementAction;

/**
 * Turns the form's `delivery` choice into the columns that record it, for both the create and the
 * edit page — written once so the two can never disagree about what "Send now" means.
 *
 * **`status = sent` is never written here, and that is the point.** The word means "tenants have
 * been pushed this text", which only {@see SendAnnouncementAction} is in a position
 * to know. "Send now" persists a `draft` and dispatches the broadcast; the service stamps `sent`
 * together with `sent_at` and `recipients_count` when the fan-out actually completes. A page that
 * stamped `sent` optimistically would produce a record claiming a broadcast that is still sitting
 * in a queue — or, if the worker never runs, one that never happened at all, which is exactly the
 * stranded state the draft/scheduled split exists to make repairable.
 */
trait TranslatesDeliveryChoice
{
    /** Set by {@see applyDeliveryChoice} when the operator chose "now"; read after the save. */
    protected bool $shouldBroadcastAfterSave = false;

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyDeliveryChoice(array $data): array
    {
        $delivery = $data['delivery'] ?? 'now';
        unset($data['delivery']); // a choice, not a column

        $this->shouldBroadcastAfterSave = $delivery === 'now';

        $data['status'] = $delivery === 'schedule'
            ? Announcement::STATUS_SCHEDULED
            : Announcement::STATUS_DRAFT;

        // A notice that is no longer scheduled must not keep a publish time: the sweep's candidate
        // set is (status = scheduled AND publish_at <= now), so a stale timestamp is inert today
        // and a live landmine the moment someone widens that query.
        if ($delivery !== 'schedule') {
            $data['publish_at'] = null;
        }

        return $data;
    }

    /**
     * Reverse of {@see applyDeliveryChoice}, so re-opening a draft shows the choice that produced
     * it rather than defaulting back to "Send now" and re-broadcasting on a typo fix.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function restoreDeliveryChoice(array $data): array
    {
        $data['delivery'] = ($data['status'] ?? null) === Announcement::STATUS_SCHEDULED
            ? 'schedule'
            : 'draft';

        return $data;
    }
}
