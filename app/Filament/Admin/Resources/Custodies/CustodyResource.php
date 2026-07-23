<?php

namespace App\Filament\Admin\Resources\Custodies;

use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Custodies\Pages\CreateCustody;
use App\Filament\Admin\Resources\Custodies\Pages\EditCustody;
use App\Filament\Admin\Resources\Custodies\Pages\ListCustodies;
use App\Filament\Admin\Resources\Custodies\Schemas\CustodyForm;
use App\Filament\Admin\Resources\Custodies\Tables\CustodiesTable;
use App\Models\Custody;
use App\Support\TenantScope;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custodies (عهدة — module 25, Treasury), scoped to the current property (denormalised
 * asset_id). Settled-to-date is DERIVED in one subquery; outstanding is computed per
 * row. Gated by the `custodies` module + `custodies.*` permissions (accounting).
 */
class CustodyResource extends Resource
{
    // NOT Filament auto-tenancy: asset_id is set from the employee by GrantCustodyService, not the
    // panel tenant. With auto-tenancy on, Filament's `creating` hook overwrote it with the current
    // tenant — the ALL pseudo-asset in All-mode — clobbering the employee's mall. No asset_id form
    // field, so no create-guard is needed; reads are scoped in getEloquentQuery() below.
    use BypassesFilamentTenantAutoScope;
    use RoleGatedActions;

    protected static ?string $model = Custody::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWallet;

    protected static ?int $navigationSort = 45;

    protected static ?string $recordTitleAttribute = 'reference';

    protected static function permissionModule(): string
    {
        return 'custodies';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.custodies.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.custodies.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.custodies.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.custodies.group');
    }

    public static function form(Schema $schema): Schema
    {
        return CustodyForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustodiesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\CustodyTransactionsRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustodies::route('/'),
            'create' => CreateCustody::route('/create'),
            'edit' => EditCustody::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Derived settled-to-date in one subquery — no per-row N+1.
        $query = parent::getEloquentQuery()->withSum('transactions as settled_sum', 'amount');

        // Property-scope the list ourselves (Filament auto-tenancy is off — see the trait note).
        if ($assetId = TenantScope::currentAssetId()) {
            $query->where('asset_id', $assetId);
        } elseif (($ids = TenantScope::visibleAssetIds()) !== null) {
            $query->whereIn('asset_id', $ids);
        }

        return $query;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['reference'];
    }

    /**
     * The عهدة (custody) register as CSV rows — each custodian's grant, settled-to-date and the
     * cash still in their hands, the treasury's outstanding-custody schedule. Reads the same
     * property-scoped query and derived `settled_sum` subquery the table shows (so the export can
     * never disagree with the screen) and closes with amount / settled / outstanding totals.
     *
     * @return array{headers: array<int,string>, rows: array<int, array<int, string|float>>}
     */
    public static function registerCsv(): array
    {
        $rows = [];
        $totalAmount = 0.0;
        $totalSettled = 0.0;
        $totalOutstanding = 0.0;

        /** @var Custody $custody */
        foreach (static::getEloquentQuery()->with(['employee', 'asset'])->orderByDesc('custody_date')->get() as $custody) {
            $amount = round((float) $custody->amount, 2);
            $settled = round((float) ($custody->settled_sum ?? 0), 2);
            $outstanding = round(max(0, $amount - $settled), 2);
            $totalAmount += $amount;
            $totalSettled += $settled;
            $totalOutstanding += $outstanding;

            $rows[] = [
                $custody->custody_date->format('Y-m-d'),
                (string) data_get($custody, 'employee.name', ''),
                $custody->reference ?? '',
                $custody->purpose ?? '',
                (string) data_get($custody, 'asset.name', ''),
                $amount, $settled, $outstanding,
                __('admin.enums.expense_paid_from.' . $custody->paid_from),
            ];
        }

        $rows[] = ['', __('admin.reports.csv.total'), '', '', '',
            round($totalAmount, 2), round($totalSettled, 2), round($totalOutstanding, 2), ''];

        return [
            'headers' => [
                __('admin.custodies.fields.custody_date'), __('admin.custodies.fields.custodian'),
                __('admin.custodies.fields.reference'), __('admin.custodies.fields.purpose'),
                __('admin.custodies.fields.property'), __('admin.custodies.fields.amount'),
                __('admin.custodies.fields.settled'), __('admin.custodies.fields.outstanding'),
                __('admin.custodies.fields.paid_from'),
            ],
            'rows' => $rows,
        ];
    }

    /** Server-side guard: the custodian's property must be within the user's visible set. */
    public static function assertAssetInScope(mixed $assetId): void
    {
        $visible = TenantScope::visibleAssetIds();
        if ($visible !== null && ! in_array((int) $assetId, $visible, true)) {
            abort(403);
        }
    }
}
