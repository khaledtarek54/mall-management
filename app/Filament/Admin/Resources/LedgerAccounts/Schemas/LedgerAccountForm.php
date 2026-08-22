<?php

namespace App\Filament\Admin\Resources\LedgerAccounts\Schemas;

use App\Rules\AccountCodeMatchesType;
use App\Support\CashFlowSection;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;

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
                        ->helperText(__('admin.helpers.account_code'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.account_code')),

                    Select::make('type')
                        ->label(__('admin.fields.account_type'))
                        ->options(fn () => __('admin.enums.ledger_account_type'))
                        ->required()
                        // The cash-flow field below is hidden for revenue and expense, and a
                        // `visible()` that reads a non-live field never re-evaluates — the section
                        // would stay on screen after switching type, and stay off after switching
                        // back.
                        ->live()
                        ->native(false)
                        ->helperText(__('admin.helpers.account_type')),

                    // Where this account's movement lands on the cash-flow statement (EG-28). Blank
                    // is honest rather than lazy: equity funds, everything else is working capital,
                    // and being wrong toward operating leaves the net change in cash correct.
                    Select::make('cash_flow_section')
                        ->label(__('admin.fields.cash_flow_section'))
                        ->options(fn (): array => collect(CashFlowSection::SECTIONS)
                            ->mapWithKeys(fn (string $s): array => [$s => __('admin.enums.cash_flow_section.'.$s)])
                            ->all())
                        ->native(false)
                        // Revenue and expense net into income by TYPE, so offering them a section
                        // would let an operator move revenue into investing and break the
                        // statement's own arithmetic.
                        ->visible(fn (Get $get): bool => ! in_array($get('type'), ['revenue', 'expense'], true))
                        ->helperText(__('admin.helpers.cash_flow_section'))
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.cash_flow_section')),

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
