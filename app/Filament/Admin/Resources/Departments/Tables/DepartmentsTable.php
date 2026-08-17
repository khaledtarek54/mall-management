<?php

namespace App\Filament\Admin\Resources\Departments\Tables;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Models\Department;
use App\Models\User;
use App\Services\DepartmentMessageService;
use App\Support\Filament\EntitySelectFilter;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

class DepartmentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('admin.tables.department.name'))
                    ->searchable()
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
                // Inter-department messaging (FR DEPT-2): notify this
                // department's members via the bell.
                Action::make('message')
                    ->label(__('admin.actions.message'))
                    ->icon('heroicon-o-chat-bubble-left-right')
                    ->color('info')
                    ->modalHeading(fn (Department $record) => __('admin.actions.message_heading', ['dept' => $record->name]))
                    ->schema([
                        Textarea::make('body')
                            ->label(__('admin.actions.message'))
                            ->required()
                            ->rows(4),
                    ])
                    ->visible(fn ($record) => DepartmentResource::canEdit($record))
                    // Fans a notification out to every member of the department — gate it, don't
                    // merely hide the button.
                    ->authorize(fn ($record) => DepartmentResource::canEdit($record))
                    ->action(function (Department $record, array $data) {
                        $count = app(DepartmentMessageService::class)->send($record, Auth::user(), $data['body']);

                        Notification::make()
                            ->title(__('admin.actions.message_sent', ['count' => $count]))
                            ->success()
                            ->send();
                    }),
                EditAction::make()->visible(fn ($record) => DepartmentResource::canEdit($record)),
            ]);
        // No delete / bulk-delete / trashed filter — departments are a fixed set.
    }
}
