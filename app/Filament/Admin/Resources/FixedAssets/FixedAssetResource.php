<?php

namespace App\Filament\Admin\Resources\FixedAssets;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\FixedAssets\Pages\CreateFixedAsset;
use App\Filament\Admin\Resources\FixedAssets\Pages\EditFixedAsset;
use App\Filament\Admin\Resources\FixedAssets\Pages\ListFixedAssets;
use App\Filament\Admin\Resources\FixedAssets\Schemas\FixedAssetForm;
use App\Filament\Admin\Resources\FixedAssets\Tables\FixedAssetsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\FixedAsset;
use App\Services\DepreciationService;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Fixed-asset register (module 23), scoped to the current property (direct
 * asset_id, like Unit / Warehouse). Accumulated depreciation is DERIVED in one
 * subquery; net book value + the monthly charge are computed per row. Gated by
 * the `fixed_assets` module + `fixed_assets.*` permissions.
 */
class FixedAssetResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is CLIENT-supplied (the operator picks the mall, and that
    // Select is enabled in All-Properties mode). Filament's ownership `creating` hook would force
    // asset_id to the current tenant — and in All-mode the tenant is the ALL pseudo-asset, silently
    // clobbering the chosen mall (the "Announcements tenancy trap"). BypassesFilamentTenantAutoScope
    // turns that hook off; reads are scoped in getEloquentQuery() below and the submitted asset_id is
    // re-validated by assertAssetInScope() on create + edit.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = FixedAsset::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 4;

    protected static ?string $recordTitleAttribute = 'name';

    protected static function permissionModule(): string
    {
        return 'fixed_assets';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.fixed_assets.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.fixed_assets.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.fixed_assets.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.inventory_assets');
    }

    public static function form(Schema $schema): Schema
    {
        return FixedAssetForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FixedAssetsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\DepreciationEntriesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFixedAssets::route('/'),
            'create' => CreateFixedAsset::route('/create'),
            'edit' => EditFixedAsset::route('/{record}/edit'),
        ];
    }

    /** Property-scope the list ourselves (Filament auto-tenancy is off — see the trait note above). */
    public static function getEloquentQuery(): Builder
    {
        // Derived accumulated depreciation in one subquery — no per-row N+1.
        //
        // **This is the SQL mirror of `FixedAsset::accumulatedDepreciation()`** and must stay equal
        // to it: the table sorts and the register CSV total both read `accumulated` from here, so
        // it cannot be a PHP call. `opening_accumulated_depreciation` is added because a legacy
        // asset loaded at cut-over carries write-off that predates this system — omit it and the
        // balance-sheet schedule reports every imported asset at cost.
        // `FixedAssetOpeningBalanceTest` asserts the two expressions agree; that test is the only
        // thing keeping a second copy of a rule honest.
        $query = parent::getEloquentQuery()
            ->withSum('depreciationEntries as depreciation_charged', 'amount')
            ->addSelect(['*', DB::raw(
                'COALESCE(opening_accumulated_depreciation, 0) + COALESCE(('
                .'SELECT SUM(amount) FROM depreciation_entries '
                .'WHERE depreciation_entries.fixed_asset_id = fixed_assets.id'
                .'), 0) AS accumulated'
            )]);

        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            // All-Properties mode: a restricted user still sees only their own malls.
            $query->whereIn('asset_id', $ids);
        }

        return $query;
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

    /**
     * The fixed-asset register as CSV rows — cost, accumulated depreciation and net book value per
     * asset, the schedule that supports the balance sheet's fixed-asset line. Reads the same
     * property-scoped query (and the same derived `accumulated` subquery) the table shows, so the
     * export can never disagree with the screen, and closes with cost / accumulated / NBV totals.
     *
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    public static function registerCsv(): array
    {
        $depreciation = app(DepreciationService::class);
        $rows = [];
        $totalCost = 0.0;
        $totalAccumulated = 0.0;
        $totalNbv = 0.0;

        /** @var FixedAsset $asset */
        foreach (static::getEloquentQuery()->with('asset')->orderBy('name')->get() as $asset) {
            $cost = round((float) $asset->acquisition_cost, 2);
            $accumulated = round((float) ($asset->accumulated ?? 0), 2);
            $nbv = round($cost - $accumulated, 2);
            $totalCost += $cost;
            $totalAccumulated += $accumulated;
            $totalNbv += $nbv;

            $rows[] = [
                $asset->tag, $asset->name, $asset->category ?? '',
                (string) data_get($asset, 'asset.name', ''),
                // acquisition_date is a NOT-NULL date column — always a Carbon.
                $asset->acquisition_date->format('Y-m-d'),
                $cost, round($depreciation->monthlyAmount($asset), 2), $accumulated, $nbv,
                __('admin.fixed_assets.statuses.' . $asset->status),
            ];
        }

        $rows[] = ['', __('admin.reports.csv.total'), '', '', '',
            round($totalCost, 2), '', round($totalAccumulated, 2), round($totalNbv, 2), ''];

        return [
            'headers' => [
                __('admin.fixed_assets.fields.tag'), __('admin.fixed_assets.fields.name'),
                __('admin.fixed_assets.fields.category'), __('admin.fixed_assets.fields.property'),
                __('admin.fixed_assets.fields.acquisition_date'), __('admin.fixed_assets.fields.acquisition_cost'),
                __('admin.fixed_assets.fields.monthly'), __('admin.fixed_assets.fields.accumulated'),
                __('admin.fixed_assets.fields.net_book_value'), __('admin.fixed_assets.fields.status'),
            ],
            'rows' => $rows,
        ];
    }

    /**
     * Server-side guard against a tampered `asset_id` on create/edit. The form
     * Select scopes its options, but in "All Properties" mode the field is enabled
     * and its dehydrated value is client-supplied — so re-validate that the target
     * property is within the user's visible set (null = portfolio user, sees all).
     */
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
