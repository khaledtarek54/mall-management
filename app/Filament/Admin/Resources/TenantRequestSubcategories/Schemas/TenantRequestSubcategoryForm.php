<?php

namespace App\Filament\Admin\Resources\TenantRequestSubcategories\Schemas;

use App\Enums\TenantRequestType;
use App\Models\Trade;
use App\Support\Filament\EntitySelect;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class TenantRequestSubcategoryForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('request_type')
                ->label(__('admin.fields.request_type'))
                ->options(fn () => collect(TenantRequestType::cases())
                    ->mapWithKeys(fn (TenantRequestType $t) => [$t->value => $t->label()])->all())
                ->required()
                ->native(false)
                // Immutable: the code is unique WITHIN a type, so moving a row between types would
                // silently re-key it and orphan every request filed under the old pair.
                ->disabledOn('edit')
                ->helperText(__('admin.tenant_request_subcategories.help.request_type')),

            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(40)
                ->disabledOn('edit')
                ->helperText(__('admin.tenant_request_subcategories.help.code'))
                ->rules([
                    'regex:/^[a-z][a-z0-9_]*$/',
                    fn ($record, callable $get) => Rule::unique('tenant_request_subcategories', 'code')
                        ->where('request_type', $get('request_type'))
                        ->ignore($record?->id),
                ]),

            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(64),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(64),

            EntitySelect::make('trade_id')
                ->label(__('admin.fields.trade'))
                ->entity(Trade::class)
                ->preload()
                // Null is right for anything that is not a maintenance fault. A noise complaint, a
                // lease copy and a parking pass are problems, not crafts — and copying the category
                // across as a trade is what put `noise` and `lease_copy` in the trade column for the
                // whole of module 26's life.
                ->helperText(__('admin.tenant_request_subcategories.help.trade'))
                ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.subcategory_trade')),

            TextInput::make('sort_order')
                ->label(__('admin.fields.sort_order'))
                ->numeric()->minValue(0)->default(0),

            Toggle::make('is_active')
                ->label(__('admin.fields.is_active'))
                ->default(true)
                ->helperText(__('admin.tenant_request_subcategories.help.is_active')),
        ]);
    }
}
