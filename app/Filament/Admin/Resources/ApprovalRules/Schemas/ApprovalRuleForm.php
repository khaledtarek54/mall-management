<?php

namespace App\Filament\Admin\Resources\ApprovalRules\Schemas;

use App\Models\ApprovalRule;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ApprovalRuleForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.approval_rule'))
                ->description(__('admin.helpers.approval_rule_section'))
                ->columns(2)
                ->components([
                    Select::make('module')
                        ->label(__('admin.fields.approval_module'))
                        ->options(fn () => collect(ApprovalRule::MODULES)
                            ->mapWithKeys(fn (string $m) => [$m => __("admin.enums.approval_module.{$m}")])
                            ->all())
                        ->required()
                        ->native(false),

                    Select::make('required_permission')
                        ->label(__('admin.fields.approval_tier'))
                        ->options(fn () => collect(ApprovalRule::TIERS)
                            ->mapWithKeys(fn (string $t) => [$t => __("admin.enums.approval_tier.{$t}")])
                            ->all())
                        ->required()
                        ->native(false)
                        ->helperText(__('admin.helpers.approval_tier')),

                    TextInput::make('min_amount')
                        ->label(__('admin.fields.approval_min_amount'))
                        ->numeric()
                        ->minValue(0)
                        ->default(0)
                        ->required()
                        ->prefix('EGP'),

                    TextInput::make('max_amount')
                        ->label(__('admin.fields.approval_max_amount'))
                        ->numeric()
                        ->prefix('EGP')
                        // Mirrors the model's refusal (an inverted band matches nothing, so it
                        // would silently disable approval for its range rather than fail loudly).
                        // The model is the guard; this is the inline error, per the house rule that
                        // a form never carries a rule alone.
                        ->gt('min_amount')
                        ->helperText(__('admin.helpers.approval_max_amount')),

                    Toggle::make('is_active')
                        ->label(__('admin.fields.is_active'))
                        ->default(true)
                        ->helperText(__('admin.helpers.approval_rule_active')),
                ]),
        ]);
    }
}
