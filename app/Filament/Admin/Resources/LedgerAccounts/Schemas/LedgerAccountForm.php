<?php

namespace App\Filament\Admin\Resources\LedgerAccounts\Schemas;

use App\Rules\AccountCodeMatchesType;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class LedgerAccountForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.account_details'))
                ->columns(2)
                ->components([
                    TextInput::make('code')
                        ->label(__('admin.fields.account_code'))
                        ->required()
                        ->maxLength(20)
                        ->unique(ignoreRecord: true)
                        ->rule('regex:/^[0-9]+$/')
                        ->rule(fn (Get $get) => new AccountCodeMatchesType($get('type')))
                        ->helperText(__('admin.helpers.account_code')),

                    Select::make('type')
                        ->label(__('admin.fields.account_type'))
                        ->options(fn () => __('admin.enums.ledger_account_type'))
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.helpers.account_type')),

                    TextInput::make('name_ar')
                        ->label(__('admin.fields.account_name_ar'))
                        ->required()
                        ->maxLength(255),

                    TextInput::make('name_en')
                        ->label(__('admin.fields.account_name_en'))
                        ->required()
                        ->maxLength(255),

                    Toggle::make('is_postable')
                        ->label(__('admin.fields.is_postable'))
                        ->helperText(__('admin.helpers.is_postable'))
                        ->default(false),

                    Toggle::make('is_active')
                        ->label(__('admin.fields.is_active'))
                        ->default(true),

                    Textarea::make('description')
                        ->label(__('admin.fields.account_description'))
                        ->rows(2)
                        ->columnSpanFull(),
                ]),
        ]);
    }
}
