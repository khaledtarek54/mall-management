<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Filament\Admin\Resources\Users\UserResource;
use Carbon\Carbon;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Models\Role;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.users.name'))
                    ->searchable()
                    ->sortable()
                    ->weight('bold'),
                TextColumn::make('email')
                    ->label(__('admin.fields.email'))
                    ->searchable()
                    ->icon('heroicon-m-envelope')
                    ->copyable(),
                TextColumn::make('roles.name')
                    ->label(__('admin.users.role'))
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'super_admin' => 'danger',
                        'manager' => 'warning',
                        'viewer' => 'gray',
                        default => 'primary',
                    })
                    ->formatStateUsing(fn (string $state) => __("admin.users.roles_list.{$state}", [], $state)),
                TextColumn::make('created_at')
                    ->label(__('admin.users.created'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('roles')
                    ->label(__('admin.users.role'))
                    ->relationship('roles', 'name')
                    ->options(fn () => Role::pluck('name', 'name')),
                Filter::make('created_range')
                    ->label(__('admin.users.created'))
                    ->schema([
                        DatePicker::make('created_from')
                            ->label(__('admin.filters.created_from'))
                            ->native(false),
                        DatePicker::make('created_until')
                            ->label(__('admin.filters.created_until'))
                            ->native(false),
                    ])
                    ->columns(2)
                    ->query(fn (Builder $query, array $data): Builder => $query
                        ->when($data['created_from'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '>=', $date))
                        ->when($data['created_until'] ?? null, fn (Builder $q, $date) => $q->whereDate('created_at', '<=', $date)))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        if ($data['created_from'] ?? null) {
                            $indicators[] = __('admin.filters.created_from').': '.Carbon::parse($data['created_from'])->format('d/m/Y');
                        }
                        if ($data['created_until'] ?? null) {
                            $indicators[] = __('admin.filters.created_until').': '.Carbon::parse($data['created_until'])->format('d/m/Y');
                        }

                        return $indicators;
                    }),
            ])
            ->filtersFormColumns(2)
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => UserResource::canView($record))
                    ->authorize(fn ($record) => UserResource::canView($record)),
                // Navigate to the Edit PAGE (not a modal): EditUser::afterSave is
                // where the super_admin guard + the role-change audit run. A modal
                // EditAction would save via its own handler and bypass both.
                EditAction::make()
                    ->url(fn ($record): string => UserResource::getUrl('edit', ['record' => $record])),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            ->defaultSort('id')
            ->emptyStateIcon('heroicon-o-users')
            ->emptyStateHeading(__('admin.empty.users.heading'))
            ->emptyStateDescription(__('admin.empty.users.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.users.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
