<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Concerns\RendersNotificationCentre;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * The operator's own alert history — everything the bell has ever shown them, in full.
 *
 * Deliberately NOT in a navigation group. Every other item in the sidebar is a module the operator
 * works IN; this is about them, like the profile menu, and filing it under Settings or Operations
 * would say it belongs to whoever owns that group. It sits at the top level with an unread badge,
 * which is also the only way a reader who has never opened the bell dropdown discovers it exists.
 *
 * No permission gates it and none should: `notificationsQuery()` is scoped to
 * `$user->notifications()`, so the page can only ever render rows addressed to the person reading
 * it. A `{module}.view` check here would gate a reader out of their own mail.
 */
class NotificationCenter extends Page implements HasTable
{
    use InteractsWithTable;
    use RendersNotificationCentre;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            ...$this->notificationCentreHeaderActions(),
        ];
    }

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?string $slug = 'notifications';

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.notification-center';

    protected function panelId(): string
    {
        return 'admin';
    }

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
        return __('admin.notifications.centre.subheading');
    }

    /** Top level, beside the dashboard — this belongs to the reader, not to a module. */
    public static function getNavigationGroup(): ?string
    {
        return null;
    }

    /** The count that makes the sidebar worth glancing at; blank rather than a zero. */
    public static function getNavigationBadge(): ?string
    {
        $user = Auth::user();

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
        return Auth::check();
    }
}
