<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders;

use App\Filament\Admin\RelationManagers\MaintenanceChecklistRelationManager;
use App\Filament\Admin\RelationManagers\WorkOrderPartsRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages\CreateMaintenanceWorkOrder;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages\EditMaintenanceWorkOrder;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Pages\ListMaintenanceWorkOrders;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Schemas\MaintenanceWorkOrderForm;
use App\Filament\Admin\Resources\MaintenanceWorkOrders\Tables\MaintenanceWorkOrdersTable;
use App\Models\MaintenanceWorkOrder;
use App\Support\AssignmentScope;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Preventive-maintenance work orders (module 26) — the operational surface: the raised
 * facility jobs, their checklists, and completion. Property-scoped; gated by the
 * `preventive_maintenance` module + `preventive_maintenance.*` permissions.
 */
class MaintenanceWorkOrderResource extends Resource
{
    // NOT Filament auto-tenancy: the form exposes an editable, dehydrated asset_id (the operator
    // picks the mall, and that Select is enabled in All-Properties mode for a new order). Filament's
    // ownership `creating` hook would force asset_id to the current tenant — and in All-mode the
    // tenant is the ALL pseudo-asset, silently clobbering the chosen mall (the "Announcements tenancy
    // trap"). BypassesFilamentTenantAutoScope turns that hook off; reads are scoped in
    // getEloquentQuery() below (composed with AssignmentScope) and the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = MaintenanceWorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static function permissionModule(): string
    {
        return 'preventive_maintenance';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.preventive_maintenance.order.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.preventive_maintenance.order.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.preventive_maintenance.order.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.preventive_maintenance.group');
    }

    public static function form(Schema $schema): Schema
    {
        return MaintenanceWorkOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MaintenanceWorkOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MaintenanceChecklistRelationManager::class,
            WorkOrderPartsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMaintenanceWorkOrders::route('/'),
            'create' => CreateMaintenanceWorkOrder::route('/create'),
            'edit' => EditMaintenanceWorkOrder::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Derived checklist progress (total + marked) in subqueries — no per-row N+1.
        // "Marked" counts pass *and* fail: progress measures the visit's completeness,
        // not its outcome (FR-PPM-07). Covered by mwoi_order_result_index.
        $query = parent::getEloquentQuery()
            ->withCount([
                'items',
                'items as marked_items_count' => fn ($q) => $q->marked(),
                'items as failed_items_count' => fn ($q) => $q->failed(),
            ]);

        // Property scoping — Filament auto-tenancy is off (see the trait note above), so we apply the
        // per-property constraint ourselves, exactly as BypassesScopingOnAll's global scope used to.
        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // All-Properties mode: a restricted user still sees only their own malls.
            $query->whereIn('asset_id', $ids);
        }

        // FR-USR-04 — a technician sees only the jobs assigned to them. Here, in the query, so it
        // covers the record page too and cannot be cleared like a filter. Composes with the
        // property scoping above rather than replacing it: both apply.
        return AssignmentScope::apply($query, 'preventive_maintenance', 'assigned_to_user_id');
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference', 'title'];
    }

    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
