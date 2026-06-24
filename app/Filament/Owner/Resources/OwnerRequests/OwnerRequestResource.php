<?php

namespace App\Filament\Owner\Resources\OwnerRequests;

use App\Filament\Owner\Resources\OwnerRequests\Pages\CreateOwnerRequest;
use App\Filament\Owner\Resources\OwnerRequests\Pages\ListOwnerRequests;
use App\Filament\Owner\Resources\OwnerRequests\Schemas\OwnerRequestForm;
use App\Filament\Owner\Resources\OwnerRequests\Tables\OwnerRequestsTable;
use App\Models\OwnerRequest;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

class OwnerRequestResource extends Resource
{
    protected static ?string $model = OwnerRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInbox;

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('admin.resources.owner_request.plural');
    }

    public static function getModelLabel(): string
    {
        return __('admin.resources.owner_request.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('admin.resources.owner_request.plural');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.operations');
    }

    public static function form(Schema $schema): Schema
    {
        return OwnerRequestForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return OwnerRequestsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListOwnerRequests::route('/'),
            'create' => CreateOwnerRequest::route('/create'),
        ];
    }

    public static function getEloquentQuery(): Builder
    {
        // Owners see only the requests they raised.
        return parent::getEloquentQuery()
            ->where('created_by_user_id', Auth::id())
            ->with(['asset', 'assignee']);
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }
}
