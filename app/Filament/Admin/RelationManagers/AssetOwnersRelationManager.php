<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\User;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * Who legally owns this property, and what share.
 *
 * **The `asset_owner` pivot had no UI anywhere until 2026-08-11.** Only `DemoSeeder` ever wrote it,
 * so on a real install there was no way to record that Jawad owns Atriom Walk, or at what
 * percentage. Two things followed, both silent:
 *
 *  - **Owner statements had nothing to divide by.** `GenerateOwnerStatementRunService` apportions a
 *    property's net by `ownership_percentage`; with no rows there is no owner and no statement.
 *  - **An owner who signed in saw nothing.** `AssignedAssets` fails closed with a `[0]` sentinel,
 *    which is the correct choice — but it means the symptom is an empty dashboard, not an error,
 *    and nobody would have connected that to a missing pivot row.
 *
 * Distinct from `AssetStaffRelationManager`: **staff operate a property, owners own it.** The two
 * pivots grant different things and are gated differently — staff membership confers panel
 * visibility (hence `roles.edit`), whereas ownership decides whose money a statement apportions
 * (hence `assets.edit`, the authority over the property record itself).
 *
 * Tenure is a real window. `started_at`/`ended_at` are inclusive bounds and either may be null;
 * a sale is recorded by setting `ended_at`, never by deleting the row — the former owner's
 * statements must keep resolving. `AssetOwner::coversDate()` is the one predicate that reads it.
 */
class AssetOwnersRelationManager extends RelationManager
{
    protected static string $relationship = 'propertyOwners';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.sections.asset_owners');
    }

    /** Ownership is a property-record decision, not a role-management one. */
    private static function canManage(): bool
    {
        return auth()->user()?->can('assets.edit') ?? false;
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('ownership_percentage')
                ->label(__('admin.fields.ownership_percentage'))
                ->numeric()
                ->suffix('%')
                ->minValue(0.01)
                ->maxValue(100)
                ->required()
                ->helperText(__('admin.fields.ownership_percentage_helper')),
            DatePicker::make('started_at')
                ->label(__('admin.fields.owned_since'))
                ->native(false)
                ->helperText(__('admin.fields.owned_since_helper')),
            DatePicker::make('ended_at')
                ->label(__('admin.fields.owned_until'))
                ->afterOrEqual('started_at')
                ->native(false)
                ->helperText(__('admin.fields.owned_until_helper')),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.user.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('admin.tables.user.email'))
                    ->copyable(),
                TextColumn::make('pivot.ownership_percentage')
                    ->label(__('admin.fields.ownership_percentage'))
                    ->numeric(decimalPlaces: 2)
                    ->suffix('%')
                    ->weight('bold'),
                TextColumn::make('pivot.started_at')
                    ->label(__('admin.fields.owned_since'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
                TextColumn::make('pivot.ended_at')
                    ->label(__('admin.fields.owned_until'))
                    ->date('d/m/Y')
                    // A blank end date is the normal, current state — say so rather than showing
                    // an empty cell the operator has to interpret.
                    ->placeholder(__('admin.fields.owned_until_open')),
            ])
            ->headerActions([
                AttachAction::make()
                    ->visible(fn () => self::canManage())
                    ->authorize(fn () => self::canManage())
                    ->preloadRecordSelect()
                    ->recordSelect(
                        fn (Select $select) => $select
                            ->label(__('admin.fields.owner'))
                            // Owners are admin-panel RBAC users holding the `owner` role — the
                            // /owner panel was removed 2026-07-27 and is not coming back.
                            ->options(fn () => User::query()
                                ->whereHas('roles', fn ($q) => $q->where('name', 'owner'))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->searchable()
                            ->helperText(__('admin.fields.owner_helper')),
                    )
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('ownership_percentage')
                            ->label(__('admin.fields.ownership_percentage'))
                            ->numeric()
                            ->suffix('%')
                            ->minValue(0.01)
                            ->maxValue(100)
                            ->default(100)
                            ->required(),
                        DatePicker::make('started_at')
                            ->label(__('admin.fields.owned_since'))
                            ->native(false),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => self::canManage())
                    ->authorize(fn () => self::canManage()),
                DetachAction::make()
                    ->visible(fn () => self::canManage())
                    ->authorize(fn () => self::canManage())
                    // Detaching erases the tenure, and with it the basis of every statement that
                    // ever apportioned money to this owner. Selling a property is recorded by
                    // setting an end date; this is for a row entered by mistake.
                    ->modalDescription(__('admin.sections.asset_owners_detach_warning')),
            ])
            ->defaultSort('pivot_ownership_percentage', 'desc')
            ->emptyStateIcon('heroicon-o-identification')
            ->emptyStateHeading(__('admin.empty.asset_owners.heading'))
            ->emptyStateDescription(__('admin.empty.asset_owners.description'));
    }
}
