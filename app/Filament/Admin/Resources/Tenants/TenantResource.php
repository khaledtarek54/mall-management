<?php

namespace App\Filament\Admin\Resources\Tenants;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\RelationManagers\PortalUsersRelationManager;
use App\Filament\Admin\RelationManagers\TenantLeasesRelationManager;
use App\Filament\Admin\RelationManagers\TenantRequestsRelationManager;
use App\Filament\Admin\RelationManagers\TenantNotesRelationManager;
use App\Filament\Admin\RelationManagers\TenantPaymentsRelationManager;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesViaProperty;
use App\Filament\Admin\Resources\Tenants\Pages\CreateTenant;
use App\Filament\Admin\Resources\Tenants\RelationManagers\DocumentsRelationManager;
use App\Filament\Admin\Resources\Tenants\Pages\EditTenant;
use App\Filament\Admin\Resources\Tenants\Pages\ListTenants;
use App\Filament\Admin\Resources\Tenants\Pages\ViewTenant;
use App\Filament\Admin\Resources\Tenants\Schemas\TenantForm;
use App\Filament\Admin\Resources\Tenants\Schemas\TenantInfolist;
use App\Filament\Admin\Resources\Tenants\Tables\TenantsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Tenant;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantResource extends Resource
{
    use RoleGatedActions;
    use ScopesViaProperty;
    use SearchesNormalizedText;

    protected static function tenantScopeRelation(): string
    {
        return 'leases.unit';
    }

    protected static ?string $model = Tenant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedUserGroup;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.tenants');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.tenant.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.tenant.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    public static function infolist(Schema $schema): Schema
    {
        return TenantInfolist::configure($schema);
    }

    public static function form(Schema $schema): Schema
    {
        return TenantForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantsTable::configure($table);
    }

    /**
     * Scope tenants to those leased in the active property — but ALSO keep
     * tenants that have no lease yet. A just-created tenant has no lease, so
     * the plain property scope (whereHas leases.unit) would hide it: it would
     * vanish from the list and the post-create redirect to its edit page would
     * 404. Including the lease-less set keeps brand-new (unassigned) tenants
     * visible and editable until a lease ties them to a property.
     */
    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where(function (Builder $q) use ($assetId) {
                $q->whereHas(static::tenantScopeRelation(), fn (Builder $r) => $r->where('asset_id', $assetId))
                    ->orWhereDoesntHave('leases');
            });
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // "All Properties" for a restricted user — pin to their assigned set.
            $query->where(function (Builder $q) use ($ids) {
                $q->whereHas(static::tenantScopeRelation(), fn (Builder $r) => $r->whereIn('asset_id', $ids))
                    ->orWhereDoesntHave('leases');
            });
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            PortalUsersRelationManager::class,
            TenantLeasesRelationManager::class,
            DocumentsRelationManager::class,
            TenantPaymentsRelationManager::class,
            TenantRequestsRelationManager::class,
            TenantNotesRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenants::route('/'),
            'create' => CreateTenant::route('/create'),
            'view' => ViewTenant::route('/{record}'),
            'edit' => EditTenant::route('/{record}/edit'),
        ];
    }

    /**
     * Searched through the fold-normalized blob, never a raw column.
     *
     * Every path ends in `search_text` on purpose — see
     * App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
        ];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.tenant.phone') => $record->phone,
            __('admin.tables.tenant.email') => $record->email,
            __('admin.tables.common.status') => __("admin.statuses.tenant.{$record->status}"),
        ];
    }
}
