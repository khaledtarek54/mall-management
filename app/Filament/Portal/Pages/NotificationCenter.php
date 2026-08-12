<?php

namespace App\Filament\Portal\Pages;

use App\Filament\Concerns\RendersNotificationCentre;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;

/**
 * The tenant's own alert history. Same page as the operator's, minus the property segment — the
 * portal has no Filament tenancy, so this lives at `/portal/notifications` where the admin one
 * lives at `/admin/{property}/notifications`. Two panels, two URL shapes, one behaviour.
 *
 * It matters more here than on the admin side. A retailer's bell carries the things they are most
 * likely to need twice: the invoice that was issued, the violation notice, the announcement about
 * next month's opening hours — and two of those three have no resource in this panel to open, so
 * without this page the notification WAS the record and the bell was the only copy.
 *
 * Scoped to the signed-in `TenantUser` by `notificationsQuery()`. A tenant's staff login sees its
 * own notifications and no one else's, including its own tenant-admin's — the same rule the rest
 * of the portal follows.
 */
class NotificationCenter extends Page implements HasTable
{
    use InteractsWithTable;
    use RendersNotificationCentre;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $slug = 'notifications';

    protected static ?int $navigationSort = 90;

    protected string $view = 'filament.pages.notification-center';

    public function table(Table $table): Table
    {
        return $this->notificationCentreTable($table);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.notifications.centre.nav_label');
    }

    public function getTitle(): string
    {
        return __('admin.notifications.centre.page_title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.notifications.centre.subheading_portal');
    }

    public static function getNavigationBadge(): ?string
    {
        $user = Filament::auth()->user();

        if (! $user) {
            return null;
        }

        $unread = $user->unreadNotifications()->where('data->format', 'filament')->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function canAccess(): bool
    {
        return Filament::auth()->check();
    }
}
