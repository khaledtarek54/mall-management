<?php

namespace App\Filament\Portal\Resources\Announcements;

use App\Filament\Portal\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Portal\Resources\Announcements\Pages\ViewAnnouncement;
use App\Filament\Portal\Resources\Announcements\Schemas\AnnouncementInfolist;
use App\Filament\Portal\Resources\Announcements\Tables\AnnouncementsTable;
use App\Models\Announcement;
use App\Support\Portal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mall news, on the web — the portal twin of `GET /api/v1/me/announcements`.
 *
 * The retailer's staff do not all carry the mobile app, and the person who needs to know the
 * loading bay is shut on Friday is often the one sitting at the shop's back-office PC. Until
 * notices became posts there was nothing here at all: the operator's only channel to a tenant was
 * a bell row, and the notification centre was the sole place it could be re-read — a screen a
 * retailer visits to clear a badge, not to look something up.
 *
 * **Strictly read-only, and scoped to the recipient rows.** `Announcement::liveFor()` is the same
 * predicate the mobile API uses, so the two surfaces cannot disagree about which notices this
 * retailer was sent — and it answers from the recipient list rather than from current property
 * membership, so a notice does not appear or vanish because a lease started or ended.
 */
class AnnouncementResource extends Resource
{
    /**
     * Deliberately absent from global search — stated in `SearchPolicy::GLOBAL_SEARCH_EXEMPT`,
     * which the conformance gate reads. Do not flip without removing that entry.
     */
    protected static bool $isGloballySearchable = false;

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 7;

    public static function getNavigationLabel(): string
    {
        return __('admin.announcements.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.announcements.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.announcements.plural');
    }

    /**
     * Unread notices, on the sidebar. The one number a retailer wants without opening anything.
     *
     * Counted with the same predicate the list uses, so the badge can never promise rows the
     * screen cannot show.
     */
    public static function getNavigationBadge(): ?string
    {
        $tenantId = Portal::tenantId();

        if ($tenantId === null) {
            return null;
        }

        $unread = Announcement::query()
            ->liveFor($tenantId)
            ->whereHas('recipients', fn (Builder $q) => $q
                ->where('tenant_id', $tenantId)
                ->whereNull('read_at'))
            ->count();

        return $unread > 0 ? (string) $unread : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    /**
     * The notices this tenant was actually sent, still live. One predicate, shared with the API.
     *
     * A signed-out or tenant-less session gets `whereRaw('1 = 0')` rather than an unscoped query:
     * the failure mode of a null tenant id must be "see nothing", never "see everything".
     */
    public static function getEloquentQuery(): Builder
    {
        $tenantId = Portal::tenantId();

        if ($tenantId === null) {
            return parent::getEloquentQuery()->whereRaw('1 = 0');
        }

        return parent::getEloquentQuery()
            ->liveFor($tenantId)
            ->with([
                'recipients' => fn ($q) => $q->where('tenant_id', $tenantId),
                'asset:id,code,name',
            ]);
    }

    /** Nothing here is writable by a retailer — a notice is the operator's record, not theirs. */
    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return AnnouncementInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListAnnouncements::route('/'),
            'view' => ViewAnnouncement::route('/{record}'),
        ];
    }
}
