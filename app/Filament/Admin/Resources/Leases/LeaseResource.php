<?php

namespace App\Filament\Admin\Resources\Leases;

use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesViaProperty;
use App\Filament\Admin\Resources\Leases\Pages\CreateLease;
use App\Filament\Admin\Resources\Leases\Pages\EditLease;
use App\Filament\Admin\Resources\Leases\Pages\ListLeases;
use App\Filament\Admin\Resources\Leases\Schemas\LeaseForm;
use App\Filament\Admin\Resources\Leases\Tables\LeasesTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Lease;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class LeaseResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesViaProperty;
    use SearchesNormalizedText;

    protected static function tenantScopeRelation(): string
    {
        return 'unit';
    }

    protected static ?string $model = Lease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'reference';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.leases');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.lease.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.lease.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.leasing');
    }

    public static function form(Schema $schema): Schema
    {
        return LeaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LeasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            // The charge schedule first: it is what the lease bills, and phase 1 made it a
            // ladder rather than a single number.
            \App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager::class,
            \App\Filament\Admin\RelationManagers\LeaseOptionsRelationManager::class,
            \App\Filament\Admin\RelationManagers\LeaseInvoicesRelationManager::class,
            \App\Filament\Admin\RelationManagers\LeaseCamTermsRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeases::route('/'),
            'create' => CreateLease::route('/create'),
            'edit' => EditLease::route('/{record}/edit'),
        ];
    }

    /**
     * By lease reference, or by the tenant or unit an operator names instead.
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
            __('admin.tables.lease.rent') => 'EGP ' . number_format((float) $record->base_rent_monthly, 2),
            __('admin.tables.common.status') => __("admin.statuses.lease.{$record->status}"),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['tenant', 'unit']);
    }
}
