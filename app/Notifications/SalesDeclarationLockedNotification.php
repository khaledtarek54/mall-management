<?php

namespace App\Notifications;

use App\Models\TenantSalesDeclaration;
use App\Services\PercentageRentCalculationService;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;

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
        $working = app(PercentageRentCalculationService::class)->explain($this->declaration);

        $mail = (new MailMessage)
            ->subject(__('admin.notifications.sales_locked_subject', [
                'period' => $this->declaration->periodLabel(),
            ]))
            ->line($this->wording('sales_locked_body'));

        // Annual (cumulative) lease: spell out the running total the charge is based on, so the tenant
        // can see WHY this month's percentage rent is what it is — or why it's zero (still under the
        // year's breakpoint). Without this a single month's figure on an annual deal is inexplicable.
        if (($working['frequency'] ?? null) === 'annual') {
            $mail->line(__('admin.notifications.sales_locked_annual_context', [
                'year' => Carbon::parse($this->declaration->period_start)->year,
                'cumulative' => 'EGP '.number_format((float) ($working['cumulative_ytd_sales'] ?? 0), 2),
                'breakpoint' => 'EGP '.number_format((float) ($working['breakpoint'] ?? 0), 2),
            ]));
        }

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
            'body' => $this->wording('sales_locked_short'),
            'icon' => 'heroicon-o-lock-closed',
            'color' => 'warning',
            'format' => 'filament', // Filament's bell only renders notifications tagged with this
            'duration' => 'persistent', // stay until dismissed (a non-persistent toast auto-deletes the row after ~6s)
        ];
    }

    /**
     * The sentence follows the LEASE (SW-254): "percentage rent owed: EGP 0.00" to a tenant whose
     * lease charges none names a clause they do not have, so a disclosure-only lease gets the
     * `_disclosure` twin of each key and no amount at all.
     */
    private function wording(string $key): string
    {
        if (! $this->declaration->lease?->has_percentage_rent) {
            return __('admin.notifications.'.$key.'_disclosure', ['period' => $this->declaration->periodLabel()]);
        }

        return __('admin.notifications.'.$key, [
            'period' => $this->declaration->periodLabel(),
            'amount' => 'EGP '.number_format((float) $this->declaration->calculated_percentage_rent, 2),
        ]);
    }
}
