<?php

namespace App\Filament\Admin\Resources\WorkPermits;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\WorkPermits\Pages\CreateWorkPermit;
use App\Filament\Admin\Resources\WorkPermits\Pages\EditWorkPermit;
use App\Filament\Admin\Resources\WorkPermits\Pages\ListWorkPermits;
use App\Filament\Admin\Resources\WorkPermits\Schemas\WorkPermitForm;
use App\Filament\Admin\Resources\WorkPermits\Tables\WorkPermitsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\WorkPermit;
use App\Support\Modules;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * تصاريح العمل — the permit-to-work register.
 *
 * An EXTENSION rather than a Yardi construct (Voyager does not model safety permits); it follows the
 * FM/CMMS standard and ordinary safety practice. See `App\Models\WorkPermit` for the reasoning.
 *
 * **The list is the control.** What an operator needs from this register is not a form but two
 * answers: what is authorised right now, and what expired without being closed. Both are filters,
 * and the second is the one an auditor asks for.
 */
class WorkPermitResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;

    // Reads the rule from the model's own #[PropertyOwned] — a resource says THAT it is scoped,
    // never how.
    use ScopesToProperty;

    // BOTH sides of a search must fold. The blob is stored folded, so without this Filament
    // compares the operator's raw keystrokes ("PTW-2026") against "ptw20260001" and matches
    // nothing — silently, which is why every other searchable resource carries it too.
    use SearchesNormalizedText;

    protected static ?string $model = WorkPermit::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 8;

    /**
     * A permit reference is quoted, not browsed.
     *
     * "PTW-2026-0004" goes on the paperwork at the gate and gets read out on the radio when
     * something is wrong in a plant room — which is the moment somebody types it into the search
     * bar, and the moment a filtered register is no use because they are not on this screen. The
     * contractor's name is searched the same way. Same shape as the work order this permit usually
     * accompanies, which is indexed for the same reason.
     */
    protected static ?string $recordTitleAttribute = 'reference';

    public static function canAccess(): bool
    {
        return Modules::enabled('facility') && parent::canAccess();
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.work_permits');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.work_permit.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.work_permit.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.facility');
    }

    public static function form(Schema $schema): Schema
    {
        return WorkPermitForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return WorkPermitsTable::configure($table);
    }

    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
            'vendor.search_text',
            'unit.search_text',
        ];
    }

    /**
     * Context under the reference. A permit number alone does not tell an operator whether the row
     * in front of them is the welding job on the roof or the isolation in the basement — and during
     * an incident that difference is the whole question.
     *
     * @return array<string, string|null>
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        /** @var WorkPermit $record */
        return [
            __('admin.fields.type') => __('admin.enums.work_permit_type')[$record->type] ?? $record->type,
            __('admin.fields.status') => __('admin.enums.work_permit_status')[$record->status] ?? $record->status,
            __('admin.fields.location') => $record->location ?? $record->unit?->code ?? $record->area?->name,
        ];
    }

    /** Eager-load exactly what the details above reach for — otherwise one query per row per keystroke. */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['unit', 'area']);
    }

    /**
     * Permits past their window with no closure — the finding, on the sidebar.
     *
     * The hourly scan mails the property team, but the count belongs where the work is: an alert
     * somebody dismissed on Friday is the whole reason this state persists. Property-scoped like
     * every other badge, so a manager pinned to one mall counts their own.
     */
    public static function getNavigationBadge(): ?string
    {
        // `visibleAssetIds()`, never `currentAssetId()` alone — the latter is null whenever no
        // single mall is selected, and a badge that silently counts the whole portfolio for a
        // manager who holds one property is the leak this project's own rule names. With a mall
        // selected this already collapses to that one id; null means a portfolio user, who may
        // legitimately see every property's findings.
        $visible = TenantScope::visibleAssetIds();

        $count = WorkPermit::query()
            ->overdueClosure()
            ->when($visible !== null, fn ($q) => $q->whereIn('asset_id', $visible))
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.tooltips.work_permits_overdue');
    }

    public static function getRelations(): array
    {
        return [
            ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListWorkPermits::route('/'),
            'create' => CreateWorkPermit::route('/create'),
            'edit' => EditWorkPermit::route('/{record}/edit'),
        ];
    }
}
