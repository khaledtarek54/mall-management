<?php

namespace App\Filament\Admin\Resources\Disbursements;

use App\Filament\Admin\Resources\Concerns\RoleGatedActions;
use App\Filament\Admin\Resources\Concerns\ScopesToProperty;
use App\Filament\Admin\Resources\Disbursements\Pages\ListDisbursements;
use App\Filament\Admin\Resources\Disbursements\Tables\DisbursementsTable;
use App\Filament\Concerns\SearchesNormalizedText;
use App\Models\Disbursement;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

/**
 * Owner disbursements (module 32) — the payout board. Approve, pay, or cancel the payouts
 * scheduled against finalised owner statements; paying clears the Due-to-Owner liability.
 * Property-scoped on the denormalized `asset_id`; gated on `disbursements.*`.
 */
class DisbursementResource extends Resource
{
    use RoleGatedActions;
    use ScopesToProperty;
    use SearchesNormalizedText;

    protected static ?string $model = Disbursement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?string $recordTitleAttribute = 'reference';

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

    protected static function permissionModule(): string
    {
        return 'disbursements';
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.disbursements.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.disbursements.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.disbursements.plural');
    }

    public static function canApprove(): bool
    {
        return auth()->user()?->can('disbursements.approve') ?? false;
    }

    public static function canPay(): bool
    {
        return auth()->user()?->can('disbursements.pay') ?? false;
    }

    public static function canCancel(): bool
    {
        return auth()->user()?->can('disbursements.cancel') ?? false;
    }

    public static function table(Table $table): Table
    {
        return DisbursementsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisbursements::route('/'),
        ];
    }
}
