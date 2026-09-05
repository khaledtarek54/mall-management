<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Support\Filament\AttachedOnce;
use App\Support\Filament\TenureRange;
use App\Support\PermissionVocabulary;
use Filament\Actions\AttachAction;
use Filament\Actions\DetachAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Manages the `staff` relation on an Asset — which admin-panel users are
 * assigned to operate this property. Distinct from the Owners relation
 * (legal owners with ownership_percentage).
 */
class AssetStaffRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'staff';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.sections.asset_staff');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('title')
                ->label(__('admin.fields.staff_title'))
                ->maxLength(100)
                ->helperText(__('admin.fields.staff_title_helper')),
            DatePicker::make('assigned_at')
                ->label(__('admin.fields.assigned_at'))
                ->default(now())
                ->native(false),
            DatePicker::make('ended_at')
                ->label(__('admin.fields.ended_at'))
                // A one-day tenure is ordinary, so the end may EQUAL the start. Compared at
                // midnight and via minDate (which also greys out the impossible days in the
                // calendar) — see TenureRange for why `afterOrEqual('assigned_at')` was not enough.
                ->minDate(TenureRange::endsOnOrAfter('assigned_at'))
                ->native(false),
            Textarea::make('notes')
                ->label(__('admin.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // Filament guesses the inverse from the PARENT model — `assets` — which User does not
            // have, so AttachAction's duplicate-exclusion would fatal rather than filter.
            ->inverseRelationship('assignedAssets')
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.user.name'))
                    ->weight('bold')
                    ->searchable(),
                TextColumn::make('email')
                    ->label(__('admin.tables.user.email'))
                    ->copyable(),
                TextColumn::make('roles.name')
                    ->label(__('admin.tables.user.role'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => PermissionVocabulary::roleLabel($state))
                    ->color('gray'),
                TextColumn::make('pivot.title')
                    ->label(__('admin.fields.staff_title'))
                    ->placeholder('—'),
                TextColumn::make('pivot.assigned_at')
                    ->label(__('admin.fields.assigned_at'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()
                    // Attaching a user as staff GRANTS them access to this property
                    // (the asset_user pivot scopes visibility), so managing staff
                    // requires role-management authority (roles.edit = super_admin),
                    // not merely assets.edit — mirrors DepartmentMembersRelationManager.
                    ->visible(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->preloadRecordSelect()
                    // NARROW, never REPLACE — see AssetOwnersRelationManager for the crash this
                    // caused there. Filament's record select already excludes whoever is attached;
                    // a bare `->options(...)` swapped that builder out and took the exclusion with
                    // it, leaving the unique index to refuse the second attach as a 500.
                    ->recordSelectOptionsQuery(
                        // Exclude owners — admin panel users only.
                        fn (Builder $query) => $query->whereHas('roles', fn ($q) => $q->where('name', '!=', 'owner')),
                    )
                    ->recordSelect(
                        fn (Select $select) => $select
                            ->label(__('admin.fields.user')),
                    )
                    // The list is not the gate — the id still arrives in the payload.
                    ->before(fn (array $data) => AttachedOnce::assert(
                        $this->getOwnerRecord(),
                        'staff',
                        $data['recordId'] ?? null,
                        'admin.refusals.asset_staff_already_attached',
                    ))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('title')
                            ->label(__('admin.fields.staff_title'))
                            ->maxLength(100),
                        DatePicker::make('assigned_at')
                            ->label(__('admin.fields.assigned_at'))
                            ->default(now())
                            ->native(false),
                        Textarea::make('notes')
                            ->label(__('admin.fields.notes'))
                            ->rows(2),
                    ]),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('roles.edit') ?? false),
                DetachAction::make()
                    // Detaching REVOKES the user's access to this property — same
                    // role-management gate as attaching. `authorize()` beside `visible()` because a
                    // relation manager has no resource for the seam to ask, so the call site IS the gate.
                    ->visible(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('roles.edit') ?? false),
            ])
            ->defaultSort('pivot_assigned_at', 'desc');
    }
}
