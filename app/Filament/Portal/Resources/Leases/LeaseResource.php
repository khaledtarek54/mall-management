<?php

namespace App\Filament\Portal\Resources\Leases;

use App\Filament\Concerns\SearchesNormalizedText;
use App\Filament\Portal\Resources\Leases\Pages\ListLeases;
use App\Filament\Portal\Resources\Leases\Pages\ViewLease;
use App\Filament\Portal\Resources\Leases\Schemas\LeaseInfolist;
use App\Filament\Portal\Resources\Leases\Tables\LeasesTable;
use App\Models\Lease;
use App\Support\Portal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tenant's own lease(s), in the portal (module 03).
 *
 * The portal doc claims a tenant "sees the same lease, invoices and maintenance requests" — but
 * there was no lease surface at all: a tenant could not see their own terms (rent, dates, escalation,
 * percentage rent, deposit) or download their signed lease. This is that surface, strictly read-only
 * and scoped to the signed-in tenant.
 */
class LeaseResource extends Resource
{
    use SearchesNormalizedText;

    protected static ?string $model = Lease::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static ?int $navigationSort = 1;

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
            'unit.search_text',
        ];
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.lease.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.lease.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.lease.plural');
    }

    public static function table(Table $table): Table
    {
        return LeasesTable::configure($table);
    }

    public static function infolist(Schema $schema): Schema
    {
        return LeaseInfolist::configure($schema);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLeases::route('/'),
            'view' => ViewLease::route('/{record}'),
        ];
    }

    /**
     * Hidden from a UNIT OWNER, who signs neither.
     *
     * Voyager's condo owner portal shows dues, statements and requests — not leases and not sales
     * declarations. An empty screen is worse than no screen here: it invites the owner to wonder
     * what is missing from it. A retailer's portal is unchanged.
     */
    public static function shouldRegisterNavigation(): bool
    {
        return ! (Portal::tenant()?->isUnitOwner() ?? false);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['unit.asset'])
            // The signed-in tenant's own leases only.
            ->where('tenant_id', Portal::tenantId())
            // …and only leases that ARE a document. `draft` and `pending_approval` are terms
            // nobody has agreed yet — the tenant reading their own rent, term and deposit off a
            // lease still being negotiated, and treating it as settled. Same rule as
            // `TenantVisibility` applies to invoices and credit notes, and the same reason: whose
            // row is this, and has it been agreed, are TWO questions.
            //
            // EXCLUDED rather than allowlisted, deliberately: `expired`, `renewed`, `terminated`
            // and `cancelled` all still explain a tenancy the tenant remembers, and losing a real
            // one from their history is the worse failure. A status this list has not heard of is
            // visible, and must be hidden on purpose.
            ->whereNotIn('status', ['draft', 'pending_approval']);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
