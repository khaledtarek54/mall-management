<?php

namespace App\Filament\Admin\Resources\CreditNotes;

use App\Filament\Admin\Resources\CreditNotes\Pages\CreateCreditNote;
use App\Filament\Admin\Resources\CreditNotes\Pages\EditCreditNote;
use App\Filament\Admin\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Admin\Resources\CreditNotes\Schemas\CreditNoteForm;
use App\Filament\Admin\Resources\CreditNotes\Tables\CreditNotesTable;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
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
    use RoleGatedActions;

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
        return __('admin.groups.billing');
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

    public static function getRecordRouteBindingEloquentQuery(): Builder
    {
        return parent::getRecordRouteBindingEloquentQuery()
            ->withoutGlobalScopes([SoftDeletingScope::class]);
    }

    public static function scopeEloquentQueryToTenant(Builder $query, ?\Illuminate\Database\Eloquent\Model $tenant): Builder
    {
        return $query;
    }

    public static function getEloquentQuery(): Builder
    {
        $query = parent::getEloquentQuery();

        if ($tenant = \Filament\Facades\Filament::getTenant()) {
            $tenantId = $tenant->getKey();
            // Scope via the linked lease's unit's asset. Standalone credit
            // notes (no lease_id) are visible regardless — they're tenant-
            // level adjustments, not asset-scoped.
            $query->where(function ($q) use ($tenantId) {
                $q->whereNull('lease_id')
                  ->orWhereHas('lease.unit', fn ($q2) => $q2->where('asset_id', $tenantId));
            });
        }

        return $query;
    }

    public static function getGloballySearchableAttributes(): array
    {
        return ['number', 'tenant.name', 'invoice.number'];
    }

    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.tables.credit_note.tenant') => $record->tenant?->name,
            __('admin.tables.credit_note.total') => 'EGP ' . number_format((float) $record->total, 2),
            __('admin.tables.common.status') => __("admin.statuses.credit_note.{$record->status}"),
        ];
    }

    public static function getNavigationBadge(): ?string
    {
        // Count credit notes ready to apply — `issued` status with balance remaining.
        $count = static::getModel()::query()
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
