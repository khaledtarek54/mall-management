<?php

namespace App\Filament\Portal\Resources\CreditNotes;

use App\Filament\Concerns\SearchesNormalizedText;
use App\Filament\Portal\Resources\CreditNotes\Pages\ListCreditNotes;
use App\Filament\Portal\Resources\CreditNotes\Pages\ViewCreditNote;
use App\Filament\Portal\Resources\CreditNotes\Schemas\CreditNoteInfolist;
use App\Filament\Portal\Resources\CreditNotes\Tables\CreditNotesTable;
use App\Models\CreditNote;
use App\Support\Portal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * The tenant's own credit notes — money the landlord owes back or has already set against a bill.
 *
 * **Missing data was never the problem.** `/api/v1/me/credit-notes` has served these per tenant for
 * a long time, so the mobile app could show a credit the portal could not: the same tenant, the same
 * records, one renderer short. A tenant who could see an invoice drop by 12,000 with no explanation
 * on any screen they could open had to telephone to ask why.
 *
 * Read-only, like every money screen on this panel. A credit note is raised by the operator through
 * `CreditNoteService`, which is where the GL entry and the un-apply path live; a create or edit
 * button here would be a second way to move money, thinner than the first.
 *
 * **Both narrowings are required and they answer different questions.** `where('tenant_id', …)`
 * answers *whose row is this* — and `visibleToTenant()` answers *has this been raised at all*.
 * `credit_notes.status` DEFAULTS to `draft` at the column, so a draft is what any create that omits
 * the status produces; without the second scope a tenant sees a credit nobody has approved and
 * counts on money that may never arrive. Same pairing as the portal invoice list, and it is the
 * pairing `TenantNeverSeesADraftTest` exists to hold.
 */
class CreditNoteResource extends Resource
{
    use SearchesNormalizedText;

    protected static ?string $model = CreditNote::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedReceiptRefund;

    /** Directly under Invoices: a credit note is only ever read next to the bill it reduces. */
    protected static ?int $navigationSort = 2;

    protected static ?string $recordTitleAttribute = 'number';

    /**
     * Always the folded blob, never a raw column — see App\Filament\Concerns\SearchesNormalizedText.
     *
     * @return array<string>
     */
    public static function getGloballySearchableAttributes(): array
    {
        return [
            'search_text',
        ];
    }

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

    public static function infolist(Schema $schema): Schema
    {
        return CreditNoteInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CreditNotesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditNotes::route('/'),
            'view' => ViewCreditNote::route('/{record}'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->visibleToTenant()
            ->where('tenant_id', Portal::tenantId());
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
