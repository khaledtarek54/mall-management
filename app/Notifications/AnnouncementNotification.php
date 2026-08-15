<?php

namespace App\Notifications;

use App\Models\Announcement;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Delivers an operator broadcast to a tenant's in-app bell + mobile devices.
 * Bell + push only — no email (informational blasts shouldn't fill inboxes).
 * The mobile inbox reads the Tenant's `database` rows; the push fans out to the
 * Tenant's registered devices via the Phase-1 FCM pipeline.
 *
 * **The alert is now a pointer, not the whole notice.** It carries the headline and an excerpt so
 * a lock-screen push still says something useful, and `announcement_id` so a tap opens the post
 * itself — where the full body, the artwork and the category live, and where it can still be read
 * next month. Before the notice became a post, the payload WAS the record and there was nowhere
 * for a tap to go.
 *
 * **The text is resolved per reader.** `titleFor()`/`bodyFor()` read the ambient locale, which is
 * exactly the seam `BellChannel` drives: it re-renders `toDatabase()` once per supported language
 * and stores every result under `data.i18n`, and Laravel's `NotificationSender` wraps each
 * recipient's push in `withLocale()`. So one broadcast reaches an Arabic retailer in Arabic and an
 * English one in English, from one operator action. With a single `title` column there was only
 * ever one answer to store, and that machinery — already present — had nothing to do.
 */
class AnnouncementNotification extends Notification
{
    use Queueable;

    /** Enough for a lock-screen line; the post carries the rest. */
    private const EXCERPT_LENGTH = 160;

    public function __construct(public Announcement $announcement) {}

    public function via(object $notifiable): array
    {
        return ['database', 'push'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'announcement',
            'announcement_id' => $this->announcement->id,
            // The app renders a category chip from this and colours emergencies differently. A
            // token, not a sentence — it resolves through `admin.announcements.categories.*` at
            // READ time, so a wording change reaches rows written years ago.
            'announcement_category' => $this->announcement->category,
            // Operator-entered content, quoted as written — never a translation key. This is the
            // case `NotificationLocaleConformanceTest` exempts by name; what makes it bilingual is
            // the operator having written both columns, not a catalogue.
            'title' => $this->announcement->titleFor(),
            'body' => str($this->announcement->bodyFor())->limit(self::EXCERPT_LENGTH)->toString(),
            'icon' => 'heroicon-o-megaphone',
            'color' => $this->announcement->category === Announcement::CATEGORY_EMERGENCY ? 'danger' : 'info',
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
