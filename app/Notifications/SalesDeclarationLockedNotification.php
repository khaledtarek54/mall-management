<?php

namespace App\Notifications;

use App\Models\TenantSalesDeclaration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class SalesDeclarationLockedNotification extends Notification
{
    use Queueable;

    public function __construct(public TenantSalesDeclaration $declaration) {}

    public function via(object $notifiable): array
    {
        return ['mail', 'database', 'push'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__('admin.notifications.sales_locked_subject', [
                'period' => $this->declaration->periodLabel(),
            ]))
            ->line(__('admin.notifications.sales_locked_body', [
                'period' => $this->declaration->periodLabel(),
                'amount' => 'EGP '.number_format((float) $this->declaration->calculated_percentage_rent, 2),
            ]));

        // The billing hint only holds when an overage was actually billed — a locked declaration
        // that was UNDER threshold (owed 0) creates no invoice, so claiming "billed on a separate
        // invoice" would be a false statement that generates "where's my invoice?" tickets.
        if ((float) $this->declaration->calculated_percentage_rent > 0) {
            $mail->line(__('admin.notifications.sales_locked_billing_hint'));
        }

        return $mail;
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'sales_declaration_locked',
            'declaration_id' => $this->declaration->id,
            'period' => $this->declaration->periodLabel(),
            'amount' => (float) $this->declaration->calculated_percentage_rent,
            'title' => __('admin.notifications.sales_locked_title'),
            'body' => __('admin.notifications.sales_locked_short', [
                'period' => $this->declaration->periodLabel(),
                'amount' => 'EGP '.number_format((float) $this->declaration->calculated_percentage_rent, 2),
            ]),
            'icon' => 'heroicon-o-lock-closed',
            'color' => 'warning',
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
