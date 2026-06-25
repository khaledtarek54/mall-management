<?php

namespace App\Notifications;

use App\Models\TenantSalesDeclaration;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * Operator-side bell entry when a tenant submits a sales declaration from
 * the portal. Routes to manager + leasing users assigned to the
 * lease's asset. Mail intentionally skipped.
 */
class SalesDeclarationSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(public TenantSalesDeclaration $declaration) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        return [
            'type' => 'sales_declaration_submitted',
            'declaration_id' => $this->declaration->id,
            'tenant' => $this->declaration->lease?->tenant?->name,
            'unit' => $this->declaration->lease?->unit?->code,
            'period' => $this->declaration->periodLabel(),
            'declared_sales' => (float) $this->declaration->declared_sales,
            'title' => __('admin.notifications.sales_submitted_title'),
            'body' => __('admin.notifications.sales_submitted_body', [
                'tenant' => $this->declaration->lease?->tenant?->name ?? '—',
                'unit' => $this->declaration->lease?->unit?->code ?? '—',
                'period' => $this->declaration->periodLabel(),
                'sales' => 'EGP '.number_format((float) $this->declaration->declared_sales, 2),
            ]),
            'icon' => 'heroicon-o-presentation-chart-line',
            'color' => 'warning',
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }
}
