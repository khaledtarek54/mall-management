<?php

namespace App\Filament\Admin\Resources\Announcements;

use App\Filament\Admin\Resources\Announcements\Pages\CreateAnnouncement;
use App\Filament\Admin\Resources\Announcements\Pages\ListAnnouncements;
use App\Filament\Admin\Resources\Announcements\Schemas\AnnouncementForm;
use App\Filament\Admin\Resources\Announcements\Tables\AnnouncementsTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Models\Announcement;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Operator broadcasts to a property's active tenants (bell + mobile push).
 * Property-owned (direct asset_id). Composing an announcement IS the send —
 * records are immutable after creation (no edit page), so the write attack
 * surface is create-only. Gated by `announcements.*` permissions.
 *
 * The target property is CLIENT-SUPPLIED (the operator picks which mall to
 * broadcast to), so this deliberately does NOT use Filament's tenancy ownership
 * (`$tenantOwnershipRelationshipName`): that registers a model `creating` hook
 * which force-associates asset_id with the *current* panel tenant, and in
 * "All Properties" mode the tenant is the ALL pseudo-asset — it would silently
 * overwrite the chosen property and broadcast to nobody (no unit belongs to ALL,
 * and the record can't be edited afterwards). BypassesFilamentTenantAutoScope
 * turns that hook off; reads are scoped explicitly in getEloquentQuery() and the
 * submitted asset_id is re-validated by assertAssetInScope() on create.
 */
class AnnouncementResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;

    protected static ?string $model = Announcement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'title';

    protected static function permissionModule(): string
    {
        return 'announcements';
    }

    /** Property-scope the list ourselves (Filament's auto-tenancy is off — see class docblock). */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // All-Properties mode: a restricted user still only sees their own.
            $query->whereIn('asset_id', $ids);
        }

        return $query;
    }

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

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.marketing');
    }

    public static function form(Schema $schema): Schema
    {
        return AnnouncementForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return AnnouncementsTable::configure($table);
    }

    public static function getPages(): array
    {
        // No edit: an announcement is immutable once broadcast.
        return [
            'index' => ListAnnouncements::route('/'),
            'create' => CreateAnnouncement::route('/create'),
        ];
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['title', 'body'];
    }
}
