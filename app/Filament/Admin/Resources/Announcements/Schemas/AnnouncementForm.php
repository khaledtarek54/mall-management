<?php

namespace App\Filament\Admin\Resources\Announcements\Schemas;

use App\Support\TenantScope;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class AnnouncementForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Select::make('asset_id')
                ->label(__('admin.announcements.fields.property'))
                // Scoped to the user's visible properties (never leaks another mall).
                ->options(fn () => TenantScope::selectableAssetOptions())
                ->default(fn () => TenantScope::currentAssetId())
                ->disabled(fn () => TenantScope::currentAssetId() !== null)
                ->dehydrated()
                ->required()
                ->native(false)
                ->helperText(__('admin.announcements.fields.property_hint')),
            TextInput::make('title')
                ->label(__('admin.announcements.fields.title'))
                ->required()
                ->maxLength(120),
            Textarea::make('body')
                ->label(__('admin.announcements.fields.body'))
                ->required()
                ->maxLength(1000)
                ->rows(5),
        ]);
    }
}
