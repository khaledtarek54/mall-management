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
        $base = config('app.mobile_reset_url', config('app.url') . '/reset-password');

        $url = $base . '?' . http_build_query([
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);

        return (new MailMessage)
            ->subject('Reset your password')
            ->line('You are receiving this email because a password reset was requested for your account.')
            ->action('Reset Password', $url)
            ->line('This link expires in 60 minutes. If you did not request a password reset, no action is required.');
    }
}
