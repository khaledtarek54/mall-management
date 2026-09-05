<?php

namespace App\Filament\Admin\RelationManagers;

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
 * Manages the `members` relation on a Department — which admin-panel staff
 * belong to this department (DEPT-4). Mirrors the Asset staff pattern; the
 * pivot carries a free-form role label + tenure dates.
 */
class DepartmentMembersRelationManager extends RelationManager
{
    protected static string $relationship = 'members';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.sections.asset_staff');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            TextInput::make('role')
                ->label(__('admin.fields.department_member_role'))
                ->maxLength(100)
                ->helperText(__('admin.fields.department_member_role_helper')),
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
            // Filament guesses the inverse from the PARENT model — `departments` — which User does
            // have, so this is belt-and-braces; naming it keeps the exclusion working if that
            // relation is ever renamed, which would otherwise fail as a fatal inside the picker.
            ->inverseRelationship('departments')
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
                TextColumn::make('pivot.role')
                    ->label(__('admin.fields.department_member_role'))
                    ->placeholder('—'),
                TextColumn::make('pivot.assigned_at')
                    ->label(__('admin.fields.assigned_at'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()
                    // Attaching a member GRANTS that department's spatie role, so
                    // managing membership requires role-management authority
                    // (roles.edit = super_admin), not merely departments.edit.
                    ->visible(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->preloadRecordSelect()
                    // NARROW, never REPLACE — the third door onto this defect, found by grepping
                    // for the shape rather than from the diff that fixed the other two. Filament's
                    // record select already excludes existing members; a bare `->options(...)` swaps
                    // that builder out, so the picker re-offers someone already in the department
                    // and `unique(user_id, department_id)` turns Attach into a 500.
                    ->recordSelectOptionsQuery(
                        // Admin-panel staff only — exclude owner-only users.
                        fn (Builder $query) => $query->whereHas('roles', fn ($q) => $q->where('name', '!=', 'owner')),
                    )
                    ->recordSelect(
                        fn (Select $select) => $select
                            ->label(__('admin.fields.user')),
                    )
                    // The list is not the gate — the id still arrives in the payload.
                    ->before(fn (array $data) => AttachedOnce::assert(
                        $this->getOwnerRecord(),
                        'members',
                        $data['recordId'] ?? null,
                        'admin.refusals.department_member_already_attached',
                    ))
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('role')
                            ->label(__('admin.fields.department_member_role'))
                            ->maxLength(100),
                        DatePicker::make('assigned_at')
                            ->label(__('admin.fields.assigned_at'))
                            ->default(now())
                            ->native(false),
                        Textarea::make('notes')
                            ->label(__('admin.fields.notes'))
                            ->rows(2),
                    ])
                    // Registering a user into a department grants the matching
                    // department role (FR DEPT — access is RBAC, not the model).
                    ->after(fn ($livewire) => $livewire->getOwnerRecord()->assignRolesToMembers()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('roles.edit') ?? false),
                DetachAction::make()
                    // Detaching REVOKES the department role — same role-management gate. `authorize()`
                    // beside `visible()`: a relation manager has no resource for the seam to ask.
                    ->visible(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('roles.edit') ?? false)
                    ->after(fn (Model $record, $livewire) => $livewire->getOwnerRecord()->unregisterMember($record)),
            ])
            ->defaultSort('pivot_assigned_at', 'desc');
    }
}
