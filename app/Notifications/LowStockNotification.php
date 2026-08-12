<?php

namespace App\Notifications;

use App\Models\LowStockAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

/**
 * "You are running out of this part, in this mall" (FR-INV-03).
 *
 * Bell only — deliberately. This is an internal restocking nudge, not a deadline or a money event;
 * the modules that email (overdue invoices, SLA breaches, lease expiry) all involve an outside
 * party or a clock. Emailing every storeman about every filter is how people learn to ignore alerts.
 */
class LowStockNotification extends Notification
{
    use Queueable;

    public function __construct(public LowStockAlert $alert) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toDatabase(object $notifiable): array
    {
        $item = $this->alert->item;

        return [
            'type' => 'low_stock',
            'title' => __('admin.inventory.low_stock.title'),
            'body' => __('admin.inventory.low_stock.body', [
                'item' => trim(($item?->sku ?? '').' — '.($item?->name ?? ''), ' —'),
                'asset' => $this->alert->asset?->name ?? '',
                'on_hand' => rtrim(rtrim(number_format((float) $this->alert->on_hand, 3), '0'), '.'),
                'unit' => $item?->unit ?? '',
                'reorder_level' => rtrim(rtrim(number_format((float) $this->alert->reorder_level, 3), '0'), '.'),
            ]),
            'low_stock_alert_id' => $this->alert->id,
            'inventory_item_id' => $this->alert->inventory_item_id,
            'asset_id' => $this->alert->asset_id,
            'icon' => 'heroicon-o-archive-box-arrow-down',
            'color' => 'warning',
            // Filament's bell queries `data->format = 'filament'` and renders nothing else. Without
            // these two keys this notification wrote a row on every scan and appeared NOWHERE — the
            // class docblock said "bell only" and the bell could not show it. Every other
            // notification in app/Notifications carries them; this one shipped without.
            'format' => 'filament',
            'duration' => 'persistent',
        ];
    }
}
