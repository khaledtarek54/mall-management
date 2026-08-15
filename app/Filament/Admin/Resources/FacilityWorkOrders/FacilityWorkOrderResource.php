<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders;

use App\Filament\Admin\RelationManagers\ServiceChecklistRelationManager;
use App\Filament\Admin\RelationManagers\WorkOrderPartsRelationManager;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\CreateFacilityWorkOrder;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\EditFacilityWorkOrder;
use App\Filament\Admin\Resources\FacilityWorkOrders\Pages\ListFacilityWorkOrders;
use App\Filament\Admin\Resources\FacilityWorkOrders\Schemas\FacilityWorkOrderForm;
use App\Filament\Admin\Resources\FacilityWorkOrders\Tables\FacilityWorkOrdersTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\FacilityWorkOrder;
use App\Support\AssignmentScope;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Preventive-maintenance work orders (module 26) — the operational surface: the raised
 * facility jobs, their checklists, and completion. Property-scoped; gated by the
 * `facility` module + `facility.*` permissions.
 */
class FacilityWorkOrderResource extends Resource
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
    use SearchesNormalizedText;

    protected static ?string $model = FacilityWorkOrder::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 1;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static function permissionModule(): string
    {
        return 'facility';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.facility.order.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.facility.order.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.facility.order.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.facility');
    }

    public static function form(Schema $schema): Schema
    {
        return FacilityWorkOrderForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FacilityWorkOrdersTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            ServiceChecklistRelationManager::class,
            WorkOrderPartsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFacilityWorkOrders::route('/'),
            'create' => CreateFacilityWorkOrder::route('/create'),
            'edit' => EditFacilityWorkOrder::route('/{record}/edit'),
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
        return AssignmentScope::apply($query, 'facility', 'assigned_to_user_id');
    }

    /**
     * By work-order reference or subject, or by the equipment or zone it targets.
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
            'equipment.search_text',
            'area.search_text',
        ];
    }

    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
    /**
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  FacilityWorkOrder  $record  Narrowed from Filament's Model signature so static analysis
     *                    can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var \App\Models\Unit|null $unit */
        $unit = $record->unit;
        /** @var \App\Models\Area|null $area */
        $area = $record->area;

        return [
            __('admin.fields.category') => $record->category,
            __('admin.tables.common.unit') => $unit->code ?? $area?->name,
            __('admin.fields.priority') => $record->priority,
        ];
    }

    /**
     * Eager-load exactly what getGlobalSearchResultDetails() reaches for. Without this
     * the details above fire one query per row, per keystroke, on top of the search.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['unit', 'area']);
    }

}
