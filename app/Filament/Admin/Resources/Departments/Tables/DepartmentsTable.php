<?php

namespace App\Filament\Admin\Resources\Departments\Tables;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Models\Department;
use App\Models\User;
use App\Support\Filament\EntitySelectFilter;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class DepartmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.department.name'))
                    // The reader's language. Searchable on BOTH columns, because an Arabic-reading
                    // operator types the Arabic name and would otherwise find nothing.
                    ->state(fn (Department $record): string => $record->label())
                    ->searchable(['name', 'name_ar'])
                    ->weight('bold'),
                TextColumn::make('code')
                    ->label(__('admin.tables.department.code'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('head.name')
                    ->label(__('admin.tables.department.head'))
                    ->placeholder('—'),
                TextColumn::make('asset.name')
                    ->label(__('admin.tables.department.scope'))
                    ->placeholder(__('admin.tables.department.global')),
                IconColumn::make('is_active')
                    ->label(__('admin.tables.department.active'))
                    ->boolean(),
                TextColumn::make('sort_order')
                    ->label(__('admin.tables.department.sort_order'))
                    ->sortable(),
            ])
            ->defaultSort('sort_order')
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('admin.tables.department.active')),
                // Departments are the one place where property scope is a real choice:
                // asset_id NULL = an operator-wide department shared by every mall.
                SelectFilter::make('scope')
                    ->label(__('admin.tables.department.scope'))
                    ->options([
                        'global' => __('admin.tables.department.global'),
                        'property' => __('admin.tables.department.property_scoped'),
                    ])
                    ->query(fn ($query, array $data) => match ($data['value'] ?? null) {
                        'global' => $query->whereNull('asset_id'),
                        'property' => $query->whereNotNull('asset_id'),
                        default => $query,
                    }),
                EntitySelectFilter::make('head_user_id')
                    ->label(__('admin.tables.department.head'))
                    ->relationship('head')
                    ->entity(User::class),
            ])
            ->emptyStateIcon('heroicon-o-building-office')
            ->emptyStateHeading(__('admin.empty.departments.heading'))
            ->emptyStateDescription(__('admin.empty.departments.description'))
            ->recordActions([
                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => DepartmentResource::canView($record))
                    ->authorize(fn ($record) => DepartmentResource::canView($record)),

                // ── The list FINDS; the record ACTS ─────────────────────────────────────
                // Defined once in App\Filament\Admin\Actions\DepartmentActions and composed onto this
                // record's own page, so opening the record is enough to act on it.
                EditAction::make()->visible(fn ($record) => DepartmentResource::canEdit($record)),
            ]);
        // No delete / bulk-delete / trashed filter — departments are a fixed set.
    }
}
