<?php

namespace App\Filament\Admin\Resources\Departments\Tables;

use App\Filament\Admin\Resources\Departments\DepartmentResource;
use App\Models\Department;
use App\Services\DepartmentMessageService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TrashedFilter;
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
                TrashedFilter::make(),
            ])
            ->recordActions([
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
                    ->action(function (Department $record, array $data) {
                        $count = app(DepartmentMessageService::class)->send($record, Auth::user(), $data['body']);

                        Notification::make()
                            ->title(__('admin.actions.message_sent', ['count' => $count]))
                            ->success()
                            ->send();
                    }),
                EditAction::make()->visible(fn ($record) => DepartmentResource::canEdit($record)),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()->visible(fn () => DepartmentResource::canDeleteAny()),
                ]),
            ]);
    }
}
