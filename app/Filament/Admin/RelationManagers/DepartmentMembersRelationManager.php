<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\User;
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
                ->label(__('admin.fields.staff_role'))
                ->maxLength(100)
                ->helperText(__('admin.fields.staff_role_helper')),
            DatePicker::make('assigned_at')
                ->label(__('admin.fields.assigned_at'))
                ->default(now())
                ->native(false),
            DatePicker::make('ended_at')
                ->label(__('admin.fields.ended_at'))
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
                    ->formatStateUsing(fn (string $state) => __("admin.users.roles_list.{$state}", [], $state))
                    ->color('gray'),
                TextColumn::make('pivot.role')
                    ->label(__('admin.fields.staff_role'))
                    ->placeholder('—'),
                TextColumn::make('pivot.assigned_at')
                    ->label(__('admin.fields.assigned_at'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->headerActions([
                AttachAction::make()
                    ->preloadRecordSelect()
                    ->recordSelect(
                        fn (Select $select) => $select
                            ->label(__('admin.fields.user'))
                            ->options(fn () => User::query()
                                // Admin-panel staff only — exclude owner-only users.
                                ->whereHas('roles', fn ($q) => $q->where('name', '!=', 'owner'))
                                ->orderBy('name')
                                ->pluck('name', 'id'))
                            ->searchable(),
                    )
                    ->schema(fn (AttachAction $action): array => [
                        $action->getRecordSelect(),
                        TextInput::make('role')
                            ->label(__('admin.fields.staff_role'))
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
                EditAction::make(),
                DetachAction::make()
                    ->after(fn (\Illuminate\Database\Eloquent\Model $record, $livewire) => $record->removeRole($livewire->getOwnerRecord()->roleName())),
            ])
            ->defaultSort('pivot_assigned_at', 'desc');
    }
}
