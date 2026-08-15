<?php

namespace App\Filament\Admin\Resources\Tenants;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\RelationManagers\PortalUsersRelationManager;
use App\Filament\Admin\RelationManagers\TenantLeasesRelationManager;
use App\Filament\Admin\RelationManagers\TenantRequestsRelationManager;
use App\Filament\Admin\RelationManagers\TenantNotesRelationManager;
use App\Filament\Admin\RelationManagers\TenantInvoicesRelationManager;
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

    /**
     * A tenant belongs on a property's register on any of three grounds: they LEASE a unit here,
     * they OWN one here (module 37 — a unit owner is a `tenants` row holding no lease at all), or
     * they are affiliated with nowhere yet, so a tenant just created does not vanish from the list
     * that created them.
     *
     * That third branch was `orWhereDoesntHave('leases')` alone, which was equivalent for as long
     * as every unleased tenant was a new one. A unit owner is PERMANENTLY unleased, so it silently
     * widened into "every owner in the portfolio shows on every property's register" — invisible
     * with one mall, wrong on the second. Ownership is now its own branch, matched to the property
     * of the unit owned, and the unaffiliated branch means genuinely unaffiliated.
     *
     * @param  array<int, int>  $assetIds
     */
    protected static function affiliatedWith(Builder $query, array $assetIds): Builder
    {
        return $query
            ->whereHas(static::tenantScopeRelation(), fn (Builder $r) => $r->whereIn('asset_id', $assetIds))
            ->orWhereHas('unitOwnerships.unit', fn (Builder $u) => $u->whereIn('asset_id', $assetIds))
            ->orWhere(fn (Builder $u) => $u->whereDoesntHave('leases')->whereDoesntHave('unitOwnerships'));
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
            $query->where(fn (Builder $q) => static::affiliatedWith($q, [$assetId]));
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // "All Properties" for a restricted user — pin to their assigned set.
            $query->where(fn (Builder $q) => static::affiliatedWith($q, $ids));
        }

        return $query;
    }

    public static function getRelations(): array
    {
        return [
            PortalUsersRelationManager::class,
            TenantLeasesRelationManager::class,
            DocumentsRelationManager::class,
            // What they OWE, beside what they have paid. The page showed the money-in side
            // and not the money-out side, so the question an operator opens a tenant to ask
            // had to be taken to the invoice register.
            TenantInvoicesRelationManager::class,
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
