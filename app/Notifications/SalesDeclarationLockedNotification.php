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
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('admin.notifications.sales_locked_subject', [
                'period' => $this->declaration->periodLabel(),
            ]))
            ->line(__('admin.notifications.sales_locked_body', [
                'period' => $this->declaration->periodLabel(),
                'amount' => 'EGP ' . number_format((float) $this->declaration->calculated_percentage_rent, 2),
            ]))
            ->line(__('admin.notifications.sales_locked_billing_hint'));
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
                'amount' => 'EGP ' . number_format((float) $this->declaration->calculated_percentage_rent, 2),
            ]),
            'icon' => 'heroicon-o-lock-closed',
            'color' => 'warning',
        ];
    }
}
