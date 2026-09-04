<?php

namespace App\Filament\Admin\Resources\Users\Tables;

use App\Filament\Admin\Resources\Users\UserResource;
use App\Models\User;
use App\Support\PermissionVocabulary;
use Carbon\Carbon;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
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
                    ->formatStateUsing(fn (string $state) => PermissionVocabulary::roleLabel($state)),
                // A suspended account still owns its history, so it stays in the list — but it
                // must be obvious at a glance which logins actually work.
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->formatStateUsing(fn (?string $state) => __('admin.users.statuses.'.($state ?: User::STATUS_ACTIVE)))
                    ->color(fn (?string $state) => $state === User::STATUS_SUSPENDED ? 'danger' : 'success')
                    ->description(function (User $record): ?string {
                        if (! $record->isSuspended() || blank($record->suspended_at)) {
                            return null;
                        }

                        $when = Carbon::parse($record->suspended_at)->format('d/m/Y');

                        return $record->suspended_reason ? $when.' · '.$record->suspended_reason : $when;
                    })
                    ->sortable(),
                TextColumn::make('created_at')
                    ->label(__('admin.users.created'))
                    ->date('d/m/Y')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->options(fn (): array => collect(User::STATUSES)
                        ->mapWithKeys(fn (string $s): array => [$s => __('admin.users.statuses.'.$s)])
                        ->all()),
                SelectFilter::make('roles')
                    ->label(__('admin.users.role'))
                    ->relationship('roles', 'name')
                    // The DROPDOWN. Without this Filament labels each option with the relationship's
                    // TITLE ATTRIBUTE — the raw identifier `super_admin` — while the badge column
                    // above renders «مدير عام». One value, two vocabularies, thirty lines apart.
                    ->getOptionLabelFromRecordUsing(fn (Role $record): string => PermissionVocabulary::roleLabel($record->name))
                    // The CHIP is a THIRD rendering and reads neither callback: `SelectFilter::setUp()`
                    // plucks the title attribute straight off the column. Same trap `EntitySelectFilter`
                    // records for entity filters; one override answers it here.
                    //
                    // The `->options(fn () => Role::pluck('name', 'name'))` that used to sit on this
                    // chain was inert — a filter that `queriesRelationships()` serves its dropdown
                    // from the relationship and never reads `getOptions()`, which is just as well,
                    // because those keys were role NAMES and `apply()` ends in `whereKey()`.
                    ->indicateUsing(function (SelectFilter $filter, array $state): array {
                        if (blank($state['value'] ?? null)) {
                            return [];
                        }

                        $name = Role::query()->whereKey($state['value'])->value('name');

                        if (blank($name)) {
                            return [];
                        }

                        $indicator = $filter->getIndicator();

                        return [$indicator instanceof Indicator
                            ? $indicator
                            : Indicator::make($indicator.': '.PermissionVocabulary::roleLabel($name))];
                    }),
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
            // Grouped into one menu. Four inline actions pushed the table past the viewport —
            // the Suspend label was clipped and the columns beyond it scrolled out of reach.
            ->recordActions([
                ActionGroup::make([
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
                    // ── The list FINDS; the record ACTS ─────────────────────────────────────
                    // Defined once in App\Filament\Admin\Actions\UserActions and composed onto this
                    // record's own page, so opening the record is enough to act on it.
                    EditAction::make()
                        ->url(fn ($record): string => UserResource::getUrl('edit', ['record' => $record])),

                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ])
            // A→Z. This opened in signup order, so finding a colleague meant searching for
            // someone you could already see was there — a register is read by name.
            ->defaultSort('name')
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
