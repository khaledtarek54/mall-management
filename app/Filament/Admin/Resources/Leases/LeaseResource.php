<?php

namespace App\Filament\Admin\Resources\Leases;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\RelationManagers\BillingForecastRelationManager;
use App\Filament\Admin\RelationManagers\ChargeScheduleRelationManager;
use App\Filament\Admin\RelationManagers\LeaseCamTermsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseDepositsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseHistoryRelationManager;
use App\Filament\Admin\RelationManagers\LeaseInvoicesRelationManager;
use App\Filament\Admin\RelationManagers\LeaseOptionsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseRentableItemsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseSalesDeclarationsRelationManager;
use App\Filament\Admin\RelationManagers\LeaseStraightLineRelationManager;
use App\Filament\Admin\RelationManagers\PercentageRentTiersRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
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

class LeaseResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

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
            // **Ordered by what an operator opens a lease to find out (2026-08-18).** It used to run
            // schedule → forecast → history → parking → options → tiers → invoices → deposits, which
            // put the two most-asked questions — what do they owe, and have they paid the deposit —
            // seventh and eighth. Reference data that only some leases have now sits after the money.
            //
            // Each tab carries the acts that change ITS OWN data, composed from
            // App\Filament\Admin\Actions\LeaseActions. Lifecycle acts (renew, extend, holdover,
            // terminate, final account) stay in the page header, because they are about the whole
            // tenancy and belong to no single tab.

            // 1. What this lease bills, and every dated step of it. Carries Change rent.
            ChargeScheduleRelationManager::class,
            // 2. What they OWE. The most common reason anyone opens a lease at all.
            LeaseInvoicesRelationManager::class,
            // 3. What is coming: the schedule's rules expanded into the invoices they will produce.
            //    Asked of the schedule alone, "what is paid each month?" has no answer.
            BillingForecastRelationManager::class,
            // 4. Money HELD rather than owed — and the shortfall nobody could see. Carries Bill
            //    security deposit and Record deposit movement.
            LeaseDepositsRelationManager::class,
            // 5. Deadlines that can still be missed. Late here is worse than late anywhere else.
            LeaseOptionsRelationManager::class,
            // 6. Why the numbers above changed, on whose authority, against which document (LE-01).
            LeaseHistoryRelationManager::class,
            // 7. Space BEYOND the premises — bays, storage, signage. Carries Assign.
            LeaseRentableItemsRelationManager::class,
            // 8. What the BOOKS recognise, against what the lease bills — EAS 49 straight-line.
            //    Only when the feature is on and this lease can be averaged; see the class.
            LeaseStraightLineRelationManager::class,
            // 9-11. Terms that only some leases have. A permanently empty table high in the list
            //       reads as "nothing has happened" rather than "this does not apply".
            PercentageRentTiersRelationManager::class,
            LeaseSalesDeclarationsRelationManager::class,
            LeaseCamTermsRelationManager::class,
            // Last, always: the audit trail is what you consult after the answer, not to find it.
            ActivitiesRelationManager::class,
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
            __('admin.tables.lease.rent') => 'EGP '.number_format((float) $record->base_rent_monthly, 2),
            __('admin.tables.common.status') => __("admin.statuses.lease.{$record->status}"),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['tenant', 'unit']);
    }
}
