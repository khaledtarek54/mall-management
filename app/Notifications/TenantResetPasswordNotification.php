<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Password-reset link for a tenant initiating reset from the mobile app.
 *
 * The mobile app owns the reset screen, so the link points at a deep link
 * configured via APP_MOBILE_RESET_URL (e.g. atriom://reset-password or an
 * https universal link). We pass the raw token + email as query params; the
 * app captures them and POSTs to /api/v1/auth/reset-password.
 */
class TenantResetPasswordNotification extends Notification
{
    use Queueable;

    public function __construct(public string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $base = config('app.mobile_reset_url', config('app.url').'/reset-password');

        $url = $base.'?'.http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        // Keyed, not typed. This was the last notification in the project written straight in
        // English — and the worst one for it: the reader is a retailer who is locked out, at the
        // one moment they cannot change the interface language to understand what they are being
        // sent. It reaches them through `Tenant::sendPasswordResetNotification()`, which Laravel
        // wraps in `withLocale($tenant->preferredLocale())`, so a tenant who has ever chosen Arabic
        // gets Arabic here without being signed in to ask.
        //
        // The expiry is read from config rather than written into the sentence — 60 was hard-coded
        // in prose beside a configurable `auth.passwords.*.expire`, so changing the window would
        // have left the email confidently stating the old one.
        $minutes = (int) config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject(__('admin.email.reset_password_subject'))
            ->line(__('admin.email.reset_password_intro'))
            ->action(__('admin.email.reset_password_action'), $url)
            ->line(__('admin.email.reset_password_expiry', ['minutes' => $minutes]));
    }
}
