<?php

namespace App\Filament\Admin\Resources\TenantRequests;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\RelationManagers\StockConsumptionRelationManager;
use App\Filament\Admin\RelationManagers\TenantRequestCommentsRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\TenantRequests\Pages\CreateTenantRequest;
use App\Filament\Admin\Resources\TenantRequests\Pages\EditTenantRequest;
use App\Filament\Admin\Resources\TenantRequests\Pages\ListTenantRequests;
use App\Filament\Admin\Resources\TenantRequests\Schemas\TenantRequestForm;
use App\Filament\Admin\Resources\TenantRequests\Tables\TenantRequestsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\TenantRequest;
use App\Support\AssignmentScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class TenantRequestResource extends Resource
{
    protected static ?string $slug = 'requests';

    use GuardsAssetInScope;
    use RoleGatedActions {
        canEdit as protected roleGatedCanEdit;
    }
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static function permissionModule(): string
    {
        return 'requests';
    }

    /**
     * Closed/cancelled work-orders are immutable (FR REQ-3). Returning false
     * here also hides the Edit / Redirect row-actions, which gate on canEdit().
     */
    public static function canEdit(Model $record): bool
    {
        if ($record instanceof TenantRequest && $record->isTerminal()) {
            return false;
        }

        return static::roleGatedCanEdit($record);
    }

    /**
     * Moving a request across its status ladder — the Change-Status action.
     *
     * Gates on `requests.change_status`, NOT on `requests.edit`. They are different rights and the
     * seeder has always said so: the `technician` role is granted `change_status` and deliberately
     * withheld `edit`, because doing the job and rewriting the record are different acts. Until
     * 2026-08-18 the action gated on canEdit(), so the one role whose entire function is to move
     * the request it is holding could not move it — the permission was granted and checked nowhere.
     * `customer_service` is the control: it holds neither, and still must not.
     *
     * The terminal rule is re-asserted here rather than delegated to canEdit(), because it is a
     * property of the RECORD (a closed request is immutable, FR REQ-3) and not of the permission.
     */
    public static function canChangeStatus(Model $record): bool
    {
        if ($record instanceof TenantRequest && $record->isTerminal()) {
            return false;
        }

        return static::hasPermission('change_status');
    }

    /**
     * Handing a request to an assignee — the Assign action. Gates on `requests.assign`, which the
     * seeder grants to exactly the roles that dispatch work (`operations`, `coordinator`, plus
     * manager's blanket grant) and withholds from `customer_service`, which captures a request and
     * hands it on. Same drift as canChangeStatus() above: the permission existed, the action
     * checked `edit` instead, so the grant described a right nothing enforced.
     */
    public static function canAssign(Model $record): bool
    {
        if ($record instanceof TenantRequest && $record->isTerminal()) {
            return false;
        }

        return static::hasPermission('assign');
    }

    protected static ?string $model = TenantRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.requests');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.tenant_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.tenant_request.plural');
    }

    public static function getNavigationBadge(): ?string
    {
        // Respect the active Filament tenant (Asset). ScopesToProperty
        // applies the unit.asset_id filter; ALL pseudo-asset bypasses scope
        // and returns the portfolio-wide count.
        $count = static::getEloquentQuery()
            ->whereIn('status', TenantRequest::OPEN_STATUSES)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): string|array|null
    {
        $hasUrgent = static::getEloquentQuery()
            ->whereIn('status', TenantRequest::OPEN_STATUSES)
            ->where('priority', 'urgent')
            ->exists();

        return $hasUrgent ? 'danger' : 'warning';
    }

    public static function form(Schema $schema): Schema
    {
        return TenantRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return TenantRequestsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            TenantRequestCommentsRelationManager::class,
            StockConsumptionRelationManager::class,
            ActivitiesRelationManager::class,
        ];
    }

    /**
     * FR-USR-04 — a technician sees only the requests assigned to them.
     *
     * **`self::` here, not `parent::`.** This resource gets its property scoping from the
     * `ScopesViaProperty` trait, and that trait's whole implementation IS `getEloquentQuery()` —
     * TenantRequest has no `asset_id`, so it is scoped through `unit.asset_id` there. Declaring
     * this method in the class SHADOWS the trait (a class method always beats a trait's), so
     * calling `parent::` would skip it and silently delete property isolation: every restricted
     * user would read every mall's requests. The isolation tests caught exactly that.
     *
     * The trait aliases its version to `scopedViaPropertyQuery()` so this can wrap rather than
     * replace it. Both scopes then apply, which is the requirement: assignment narrows within the
     * properties you may see, it never widens across them.
     *
     * NOTE the column: `tenant_requests.assigned_to`, where the work order uses
     * `assigned_to_user_id`. The two tables disagree, which is why the rule lives in one primitive.
     */
    public static function getEloquentQuery(): Builder
    {
        // No trait aliasing needed: ScopesToProperty exposes the scoping as a public primitive, so
        // the property scope and the assignment scope simply compose. The old form had to alias the
        // trait's getEloquentQuery() because that WAS its whole implementation.
        return AssignmentScope::apply(
            static::scopeToProperty(parent::getEloquentQuery()),
            'requests',
            'assigned_to',
        );
    }

    public static function getPages(): array
    {
        return [
            'index' => ListTenantRequests::route('/'),
            'create' => CreateTenantRequest::route('/create'),
            'edit' => EditTenantRequest::route('/{record}/edit'),
        ];
    }

    /**
     * By reference or subject, by the tenant, or by the unit it was raised against.
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
            'tenant.search_text',
            'unit.search_text',
        ];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.lease.tenant') => $record->tenant?->name,
            __('admin.tables.lease.unit') => $record->unit?->code,
            __('admin.tables.common.status') => __("admin.statuses.tenant_request.{$record->status}"),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['tenant', 'unit']);
    }
}
