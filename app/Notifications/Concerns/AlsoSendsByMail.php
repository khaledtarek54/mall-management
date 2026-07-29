<?php

namespace App\Notifications\Concerns;

use Illuminate\Notifications\Messages\MailMessage;

/**
 * Send a bell alert by email as well, using the SAME title and body the bell shows.
 *
 * Fourteen notifications reached `['database']` only — the in-app bell — including every one with
 * a clock attached: both SLA breaches, a vendor certificate lapsing, a contract past its notice
 * deadline (after which it auto-renews and commits money), and the general ledger refusing
 * documents. A bell is only an alert if somebody opens the app; the person who needs to act on a
 * breached SLA is, by definition, not sitting in /admin.
 *
 * **The mail is derived from `toDatabase()`, not written a second time.** Two hand-written copies
 * of the same alert drift — one gets a new field, the other doesn't, and then the email and the
 * bell disagree about what happened. Deriving means a change to the payload updates both.
 *
 * Deliberately NOT applied to the other nine database-only notifications. Off-app delivery is a
 * cost as well as a feature: a department message, an owner statement being sent, a sales
 * declaration arriving and a low-stock warning are all things you read when you next look, and
 * mailing them trains people to ignore the ones that matter.
 *
 * Requires the using class to implement `toDatabase()` returning the standard bell payload
 * (`title`, `body`, and optionally `color`).
 */
trait AlsoSendsByMail
{
    public function toMail(object $notifiable): MailMessage
    {
        /** @var array<string, mixed> $payload */
        $payload = $this->toDatabase($notifiable);

        $message = (new MailMessage)
            ->subject((string) ($payload['title'] ?? __('admin.notifications.mail_generic_subject')))
            ->line((string) ($payload['body'] ?? ''));

        // Carry the bell's severity into the mail template, so a breach doesn't arrive looking
        // like a newsletter.
        if (($payload['color'] ?? null) === 'danger') {
            $message->error();
        }

        return $message->action(__('admin.notifications.mail_open_cta'), $this->mailUrl($notifiable));
    }

    /**
     * Where the email's button goes. Override to deep-link to the record; the default is the
     * panel root, which is still better than no way back in.
     */
    protected function mailUrl(object $notifiable): string
    {
        return url('/admin');
    }
}
