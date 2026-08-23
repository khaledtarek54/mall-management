<?php

namespace App\Filament\Admin\Resources\Invoices;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\Invoices\Pages\CreateInvoice;
use App\Filament\Admin\Resources\Invoices\Pages\EditInvoice;
use App\Filament\Admin\Resources\Invoices\Pages\ListInvoices;
use App\Filament\Admin\Resources\Invoices\Schemas\InvoiceForm;
use App\Filament\Admin\Resources\Invoices\Tables\InvoicesTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Invoice;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class InvoiceResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = Invoice::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'number';

    /**
     * Triggering a BILLING RUN — the lease run and the unit-owner assessment run, both on this
     * table's header.
     *
     * Gates on `invoices.run_monthly_billing`, its own right, and not on `invoices.create`. Raising
     * one invoice and raising every invoice in the property in a single click are different acts,
     * which is exactly why the seeder has always carried a separate permission for it — granted to
     * `accounting` and, via the blanket grant, to `manager`/`mall_admin`.
     *
     * Until 2026-08-18 that permission was checked NOWHERE and both runs gated on `invoices.create`,
     * so the catalogue described a right nothing enforced. No role changes hands here: every role
     * holding `run_monthly_billing` also holds `create`, and the reverse set is empty.
     */
    public static function canRunBilling(): bool
    {
        return static::hasPermission('run_monthly_billing');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.invoices');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.invoice.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.invoice.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return InvoiceForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return InvoicesTable::configure($table);
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
            'index' => ListInvoices::route('/'),
            'create' => CreateInvoice::route('/create'),
            'edit' => EditInvoice::route('/{record}/edit'),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        // Respect the active Filament tenant (Asset). ScopesToProperty's getEloquentQuery() filters
        // on the invoice's OWN asset_id — which is what makes an owner assessment (lease_id NULL)
        // count here at all; the old lease.unit chain silently excluded every one of them. The
        // "All Properties" pseudo-asset bypasses scoping and returns the portfolio-wide count.
        $overdue = static::getEloquentQuery()
            ->where('balance', '>', 0)
            ->where('due_date', '<', now())
            ->count();

        return $overdue > 0 ? (string) $overdue : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'danger';
    }

    /**
     * An invoice is hunted by its number, but just as often by who it is for or which unit it billed.
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
            'lease.search_text',
            'lease.unit.search_text',
        ];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.invoice.tenant') => $record->tenant?->name,
            __('admin.tables.invoice.unit') => $record->lease?->unit?->code,
            __('admin.tables.invoice.balance') => 'EGP '.number_format((float) $record->balance, 2),
            __('admin.tables.common.status') => __("admin.statuses.invoice.{$record->status}"),
        ];
    }

    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['tenant', 'lease.unit']);
    }
}
