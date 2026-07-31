<?php

namespace App\Filament\Admin\Resources\CreditNotes;

use App\Filament\Admin\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Admin\Resources\CreditNotes\Schemas\CreditNoteForm;
use App\Filament\Admin\Resources\CreditNotes\Tables\CreditNotesTable;
use App\Filament\Admin\Resources\Concerns\BypassesFilamentTenantAutoScope;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\CreditNote;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletingScope;

class CreditNoteResource extends Resource
{
    use BypassesFilamentTenantAutoScope;
    use GuardsAssetInScope;
    use RoleGatedActions;
    use SearchesNormalizedText;

    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    protected static ?int $navigationSort = 3;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.credit_notes');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.credit_note.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.credit_note.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.receivables');
    }

    public static function form(Schema $schema): Schema
    {
        return CreditNoteForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CreditNotesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            \App\Filament\Admin\RelationManagers\CreditNoteApplicationsRelationManager::class,
            \App\Filament\Admin\RelationManagers\ActivitiesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
            'create' => CreateCreditNote::route('/create'),
            'edit' => EditCreditNote::route('/{record}/edit'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        // Scope both an asset-linked note (via lease.unit) AND a standalone note (no lease_id): a
        // standalone note is a TENANT-level adjustment, so it belongs to the properties where that
        // tenant leases — NOT every property. Showing it portfolio-wide leaked a restricted
        // operator another property's tenant + credit amount (and let them void/issue it).
        $ids = \App\Support\TenantScope::currentAssetId() !== null
            ? [\App\Support\TenantScope::currentAssetId()]
            : \App\Support\TenantScope::visibleAssetIds();

        if ($ids !== null) {
            $query->where(function ($q) use ($ids) {
                $q->whereHas('lease.unit', fn ($q2) => $q2->whereIn('asset_id', $ids))
                  ->orWhere(fn ($q3) => $q3->whereNull('lease_id')
                      ->whereHas('tenant.leases.unit', fn ($u) => $u->whereIn('asset_id', $ids)));
            });
        }

        return $query;
    }

    /**
     * By note number, by tenant, or by the invoice it credits.
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
            'invoice.search_text',
        ];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.credit_note.tenant') => $record->tenant?->name,
            __('admin.tables.credit_note.total') => 'EGP ' . number_format((float) $record->total, 2),
            __('admin.tables.common.status') => __("admin.statuses.credit_note.{$record->status}"),
        ];
    }

    /**
     * Eager-load the tenant the details above name. Without it, the detail line
     * fires one query per result row on every keystroke.
     */
    public static function getGlobalSearchEloquentQuery(): Builder
    {
        return parent::getGlobalSearchEloquentQuery()->with(['tenant']);
    }

    public static function getNavigationBadge(): ?string
    {
        // Count credit notes ready to apply — `issued` status with balance
        // remaining. Uses getEloquentQuery() so the per-property scope (or
        // standalone tenant-level note visibility) applied above is honored.
        $count = static::getEloquentQuery()
            ->where('status', 'issued')
            ->where('balance', '>', 0)
            ->count();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'info';
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('admin.tooltips.credit_notes_ready');
    }
}
