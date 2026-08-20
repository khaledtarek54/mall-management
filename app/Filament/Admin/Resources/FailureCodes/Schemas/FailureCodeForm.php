<?php

namespace App\Filament\Admin\Resources\FailureCodes\Schemas;

use App\Models\FailureCode;
use App\Models\Trade;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class FailureCodeForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('type')
                ->label(__('admin.fields.type'))
                ->options(fn () => collect(FailureCode::TYPES)
                    ->mapWithKeys(fn (string $t) => [$t => __("admin.facility.failure_types.{$t}")])
                    ->all())
                ->required()
                ->native(false)
                ->helperText(__('admin.facility.help.failure_type')),

            TextInput::make('code')
                ->label(__('admin.fields.code'))
                ->required()
                ->maxLength(32)
                // Unique within the TYPE, not globally: "leak" is a legitimate problem AND a
                // legitimate cause, and one row serving both would make the pickers lie.
                ->unique(ignoreRecord: true, modifyRuleUsing: fn ($rule, callable $get) => $rule->where('type', $get('type')))
                ->helperText(__('admin.facility.help.failure_code_code')),

            Select::make('trade_id')
                ->label(__('admin.facility.fields.trade'))
                ->options(fn (?FailureCode $record) => Trade::options($record?->trade_id))
                ->native(false)
                ->searchable()
                // Blank is the useful default: a code with no trade is offered on every job, which
                // is what stops a newly-added trade having an empty picker.
                ->placeholder(__('admin.facility.failure_any_trade'))
                ->helperText(__('admin.facility.help.failure_trade')),

            TextInput::make('name_en')->label(__('admin.fields.name_en'))->required()->maxLength(120),
            TextInput::make('name_ar')->label(__('admin.fields.name_ar'))->required()->maxLength(120),

            TextInput::make('sort_order')->label(__('admin.fields.sort_order'))->numeric()->default(0),

            Toggle::make('is_active')
                ->label(__('admin.fields.active'))
                ->default(true)
                ->helperText(__('admin.facility.help.failure_active')),
        ])->columns(2);
    }
}
