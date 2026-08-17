<?php

namespace App\Filament\Admin\Resources\JournalEntries;

use App\Filament\Admin\RelationManagers\ActivitiesRelationManager;
use App\Filament\Admin\Resources\Concerns\GuardsAssetInScope;
use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\JournalEntries\Pages\CreateJournalEntry;
use App\Filament\Admin\Resources\JournalEntries\Pages\EditJournalEntry;
use App\Filament\Admin\Resources\JournalEntries\Pages\ListJournalEntries;
use App\Filament\Admin\Resources\JournalEntries\Schemas\JournalEntryForm;
use App\Filament\Admin\Resources\JournalEntries\Tables\JournalEntriesTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\JournalEntry;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * قيود اليومية — manual journal entries (and a read view of auto-posted ones).
 * The chart is shared; this resource scopes by the entry's `asset_id` dimension,
 * always also showing consolidated (null-asset) company-level entries.
 */
class JournalEntryResource extends Resource
{
    use GuardsAssetInScope;
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = JournalEntry::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    public static function getNavigationLabel(): string
    {
        return __('admin.navigation.journal_entries');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.journal_entry.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.journal_entry.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.general_ledger');
    }

    public static function form(Schema $schema): Schema
    {
        return JournalEntryForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return JournalEntriesTable::configure($table);
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
            'index' => ListJournalEntries::route('/'),
            'create' => CreateJournalEntry::route('/create'),
            'edit' => EditJournalEntry::route('/{record}/edit'),
        ];
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
     * Context under the title. A bare reference does not tell an operator whether the
     * row in front of them is the one they were hunting for.
     *
     * @param  JournalEntry  $record  Narrowed from Filament's Model signature so static analysis
     *                                can see the columns — the alternative was ten baseline entries.
     */
    public static function getGlobalSearchResultDetails(Model $record): array
    {
        return [
            __('admin.fields.entry_date') => $record->entry_date->format('d/m/Y'),
            __('admin.fields.description') => app()->getLocale() === 'ar' ? $record->description_ar : $record->description_en,
        ];
    }
}
