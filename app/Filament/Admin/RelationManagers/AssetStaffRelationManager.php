<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Filament\AttachedOnce;
use App\Support\Filament\TenureRange;
use App\Support\PermissionVocabulary;
use App\Support\PropertyRoster;
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
                // A PERSON'S EMAIL LIVES ON THE PERSON, and this column is now the way to reach it.
                // Reported by the tester as "no way to edit the assigned staff email": there was
                // none from here, and there should not be an email FIELD on this modal — the
                // address is the user's LOGIN, not a fact about their assignment to this mall, and
                // editing a credential from a property tab is the wrong place for it. What was
                // missing is the ROUTE, so the address links to the user record for anyone who may
                // edit it, and stays plain copyable text for anyone who may not.
                TextColumn::make('email')
                    ->label(__('admin.tables.user.email'))
                    ->copyable()
                    // The TENANT is passed explicitly. This panel is tenant-scoped, so `getUrl()`
                    // otherwise builds the route from whatever tenant happens to be set on the
                    // request — and a relation manager already knows the property it belongs to.
                    ->url(fn (User $record): ?string => UserResource::canEdit($record)
                        ? UserResource::getUrl('edit', ['record' => $record], tenant: $this->getOwnerRecord())
                        : null)
                    ->color(fn (User $record): ?string => UserResource::canEdit($record) ? 'primary' : null)
                    ->tooltip(fn (User $record): ?string => UserResource::canEdit($record)
                        ? __('admin.tables.user.edit_person_tooltip')
                        : null),
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
                // WHETHER THIS PERSON STILL WORKS HERE. The register showed an Assigned date and an
                // Ended date and left the reader to compare them against today — for every row, in
                // their head. Derived from `AssetUser::coversDate()`, the same predicate shape the
                // ownership pivot beside it uses, so the two tenures cannot come to disagree about
                // what "current" means.
                TextColumn::make('tenure')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->state(fn (User $record): string => match (true) {
                        $record->pivot->hasEnded() => 'ended',
                        ! $record->pivot->coversDate() => 'scheduled',
                        default => 'active',
                    })
                    ->formatStateUsing(fn (string $state): string => __("admin.statuses.staff_tenure.{$state}"))
                    ->color(fn (string $state): string => match ($state) {
                        'ended' => 'danger',
                        'scheduled' => 'warning',
                        default => 'success',
                    })
                    // The end date is what the badge is derived FROM, so it belongs beside it
                    // rather than being a second thing to go and look up.
                    ->description(fn (User $record): ?string => $record->pivot->ended_at
                        ? __('admin.tables.user.ended_on', ['date' => $record->pivot->ended_at->format('d/m/Y')])
                        : null),
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
                    // Attaching GRANTS access to this property, so it is recorded — Laravel's
                    // attach() writes through the query builder and fires no model event, which is
                    // why the roster had no audit trail at all until 2026-09-05.
                    ->after(fn (Model $record) => PropertyRoster::forRecord(
                        $this->getOwnerRecord(), PropertyRoster::STAFF, $record, 'attached',
                    ))
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
                    ->after(fn (Model $record) => PropertyRoster::forRecord(
                        $this->getOwnerRecord(), PropertyRoster::STAFF, $record, 'updated',
                    ))
                    ->visible(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('roles.edit') ?? false),
                DetachAction::make()
                    ->after(fn (Model $record) => PropertyRoster::forRecord(
                        $this->getOwnerRecord(), PropertyRoster::STAFF, $record, 'detached',
                    ))
                    // Detaching REVOKES the user's access to this property — same
                    // role-management gate as attaching. `authorize()` beside `visible()` because a
                    // relation manager has no resource for the seam to ask, so the call site IS the gate.
                    ->visible(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('roles.edit') ?? false),
            ])
            ->defaultSort('pivot_assigned_at', 'desc');
    }
}
