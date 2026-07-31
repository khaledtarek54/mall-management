<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\Resources\TenantRequests\TenantRequestResource;
use App\Models\TenantRequest;
use App\Models\Tenant;
use App\Models\User;
use App\Services\TenantRequestService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class TenantRequestCommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.maintenance.comments');
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: TenantRequestComment carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->modifyQueryUsing(fn ($query) => $query->with('author')->orderBy('created_at'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.activity.when'))
                    ->dateTime('d/m/Y H:i')
                    ->sortable(),
                TextColumn::make('author_label')
                    ->label(__('admin.activity.who'))
                    ->state(function ($record): string {
                        $author = $record->author;
                        if ($author instanceof Tenant) {
                            return $author->name . ' · ' . __('admin.maintenance.author_tenant');
                        }
                        if ($author instanceof User) {
                            return $author->name . ' · ' . __('admin.maintenance.author_staff');
                        }
                        return __('admin.activity.system');
                    })
                    ->weight('medium'),
                TextColumn::make('body')
                    ->label(__('admin.maintenance.body'))
                    ->wrap()
                    ->limit(200),
                IconColumn::make('is_internal')
                    ->label(__('admin.maintenance.internal'))
                    ->boolean()
                    ->trueIcon('heroicon-o-lock-closed')
                    ->trueColor('warning')
                    ->falseIcon('heroicon-o-globe-alt')
                    ->falseColor('gray'),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.maintenance.add_comment'))
                    ->modalHeading(__('admin.maintenance.add_comment'))
                    // Gate explicitly (Filament actions default to ALLOWED) — and
                    // canEdit() is false for terminal requests, so no comments are
                    // added to a closed/cancelled ticket from here.
                    ->visible(fn (RelationManager $livewire) => TenantRequestResource::canEdit($livewire->getOwnerRecord()))
                    ->schema([
                        Textarea::make('body')
                            ->label(__('admin.maintenance.body'))
                            ->required()
                            ->rows(4)
                            ->columnSpanFull(),
                        Toggle::make('is_internal')
                            ->label(__('admin.maintenance.is_internal'))
                            ->helperText(__('admin.maintenance.is_internal_helper'))
                            ->default(false),
                    ])
                    ->using(function (array $data, RelationManager $livewire) {
                        /** @var TenantRequest $request */
                        $request = $livewire->getOwnerRecord();
                        return app(TenantRequestService::class)
                            ->comment($request, Auth::user(), $data['body'], (bool) ($data['is_internal'] ?? false));
                    }),
            ])
            ->recordActions([
                Action::make('toggleVisibility')
                    ->label(fn ($record) => $record->is_internal
                        ? __('admin.maintenance.make_public')
                        : __('admin.maintenance.make_internal'))
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->visible(fn (RelationManager $livewire) => TenantRequestResource::canEdit($livewire->getOwnerRecord()))
                    // Flipping is_internal PUBLISHES a staff note to the tenant's portal view — a
                    // disclosure, not a cosmetic flag. Gated as well as hidden.
                    ->authorize(fn (RelationManager $livewire) => TenantRequestResource::canEdit($livewire->getOwnerRecord()))
                    ->action(fn ($record) => $record->update(['is_internal' => ! $record->is_internal])),
            ])
            ->toolbarActions([])
            ->defaultSort('created_at', 'asc')
            ->paginated([10, 25]);
    }
}
