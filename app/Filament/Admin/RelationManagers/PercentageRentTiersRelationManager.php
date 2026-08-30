<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Models\Lease;
use App\Models\LeasePercentageRentTier;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * The percentage-rent breakpoint ladder (PR-02).
 *
 * Only meaningful when the lease's calculation type is **tiered** — the panel says so rather than
 * silently collecting rows that nothing reads.
 */
class PercentageRentTiersRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'percentageRentTiers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.percentage_rent_tiers.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        // Hidden unless the lease actually has percentage rent — a ladder on a lease without it is
        // noise on every other lease's page.
        return $ownerRecord instanceof Lease && (bool) $ownerRecord->has_percentage_rent;
    }

    private static function canWrite(): bool
    {
        return (bool) Auth::user()?->can('leases.edit');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(3)->components([
            TextInput::make('from_amount')
                ->label(__('admin.percentage_rent_tiers.from'))
                ->numeric()->prefix('EGP')->minValue(0)->required()
                ->helperText(__('admin.helpers.percentage_rent_tier_from'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.percentage_rent_tier_from')),
            TextInput::make('to_amount')
                ->label(__('admin.percentage_rent_tiers.to'))
                ->numeric()->prefix('EGP')->minValue(0)
                ->gt('from_amount')
                // Blank = unbounded, and that is required on the last band or the ladder stops
                // charging above its own ceiling.
                ->helperText(__('admin.helpers.percentage_rent_tier_to')),
            TextInput::make('rate')
                ->label(__('admin.fields.percentage_rent_rate'))
                ->numeric()->suffix('%')->minValue(0)->maxValue(100)->required(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->searchable(false)
            ->defaultSort('from_amount', 'asc')
            ->columns([
                TextColumn::make('band')
                    ->label(__('admin.percentage_rent_tiers.band'))
                    ->state(fn (LeasePercentageRentTier $record): string => number_format((float) $record->from_amount, 0)
                        .' → '.($record->to_amount !== null
                            ? number_format((float) $record->to_amount, 0)
                            : __('admin.percentage_rent_tiers.unbounded'))),
                TextColumn::make('rate')
                    ->label(__('admin.fields.percentage_rent_rate'))
                    ->suffix('%')
                    ->alignEnd()
                    // A 0% first band IS the breakpoint — worth saying, because it looks like a
                    // mistake otherwise.
                    ->description(fn (LeasePercentageRentTier $record): ?string => (float) $record->rate === 0.0
                        ? __('admin.percentage_rent_tiers.zero_band_is_breakpoint')
                        : null),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.actions.add_tier'))->visible(fn () => self::canWrite())->authorize(fn () => self::canWrite()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn () => self::canWrite())->authorize(fn () => self::canWrite()),
                DeleteAction::make()->visible(fn () => self::canWrite())->authorize(fn () => self::canWrite()),
            ])
            ->emptyStateHeading(__('admin.percentage_rent_tiers.empty'))
            ->emptyStateDescription(__('admin.percentage_rent_tiers.empty_description'));
    }
}
