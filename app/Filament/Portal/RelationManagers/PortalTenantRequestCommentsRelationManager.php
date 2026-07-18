<?php

namespace App\Filament\Portal\RelationManagers;

use App\Models\Tenant;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class PortalTenantRequestCommentsRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.maintenance.updates');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query
                ->where('is_internal', false)
                ->with('author')
                ->orderBy('created_at'))
            ->columns([
                TextColumn::make('created_at')
                    ->label(__('admin.activity.when'))
                    ->dateTime('d/m/Y H:i'),
                TextColumn::make('author_label')
                    ->label(__('admin.activity.who'))
                    ->state(function ($record): string {
                        $author = $record->author;
                        if ($author instanceof Tenant) {
                            return $author->name;
                        }
                        if ($author instanceof User) {
                            return __('admin.maintenance.author_property_team');
                        }
                        return __('admin.activity.system');
                    })
                    ->weight('medium'),
                TextColumn::make('body')
                    ->label(__('admin.maintenance.body'))
                    ->wrap(),
            ])
            ->headerActions([])
            ->recordActions([])
            ->toolbarActions([])
            ->defaultSort('created_at', 'asc')
            ->paginated([10, 25]);
    }
}
