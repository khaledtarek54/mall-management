<?php

namespace App\Filament\Admin\Resources\MaintenanceWorkOrders;

use App\Filament\Admin\RelationManagers\MaintenanceChecklistRelationManager;
use App\Filament\Admin\RelationManagers\WorkOrderPartsRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesScopingOnAll;
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
    use BypassesScopingOnAll;
    use RoleGatedActions;

    protected static ?string $model = MaintenanceWorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 47;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static ?string $tenantOwnershipRelationshipName = 'asset';

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
