<?php

namespace App\Filament\Admin\Resources\Payrolls\Schemas;

use App\Models\Payroll;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class PayrollForm
{
    public static function configure(Schema $schema): Schema
    {
        // Once past draft, the run's terms are settled — lock the descriptive
        // + money fields (only approval / cancellation change it after that).
        $locked = fn (?Payroll $record) => $record !== null && $record->status !== 'draft';

        // When a run has per-employee lines, its amounts DERIVE from Σ lines — the
        // header money fields are then read-only (edit the lines instead).
        $amountLocked = fn (?Payroll $record) => $locked($record) || ($record?->lines()->exists() ?? false);

        return $schema->columns(1)->components([
            Section::make(__('admin.sections.payroll_details'))
                ->columns(3)
                ->components([
                    TextInput::make('number')
                        ->label(__('admin.fields.payroll_number'))
                        ->disabled()
                        ->dehydrated(false)
                        ->placeholder(__('admin.fields.auto_generated')),

                    Select::make('asset_id')
                        ->label(__('admin.fields.property'))
                        ->options(fn () => \App\Support\TenantScope::selectableAssetOptions())
                        ->default(fn () => \App\Support\TenantScope::currentAssetId())
                        ->searchable()
                        ->preload()
                        ->placeholder(__('admin.fields.property_consolidated'))
                        ->disabled($locked),

                    DatePicker::make('period_month')
                        ->label(__('admin.fields.payroll_month'))
                        ->required()
                        ->default(now()->startOfMonth())
                        ->native(false)
                        ->disabled($locked),

                    Select::make('paid_from')
                        ->label(__('admin.fields.paid_from'))
                        ->options(fn () => __('admin.enums.expense_paid_from'))
                        ->default('bank')
                        ->native(false)
                        ->required()
                        ->disabled($locked),

                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled($locked),
                ]),

            Section::make(__('admin.sections.amounts'))
                ->columns(4)
                ->components([
                    TextInput::make('gross_salaries')
                        ->label(__('admin.fields.gross_salaries'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncNet($set, $get))
                        ->disabled($amountLocked),

                    TextInput::make('salary_tax')
                        ->label(__('admin.fields.salary_tax'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncNet($set, $get))
                        ->disabled($amountLocked),

                    TextInput::make('social_insurance')
                        ->label(__('admin.fields.social_insurance'))
                        ->prefix('EGP')
                        ->numeric()
                        ->minValue(0)
                        ->required()
                        ->default(0)
                        ->live(onBlur: true)
                        ->afterStateUpdated(fn (Set $set, Get $get) => self::syncNet($set, $get))
                        ->disabled($amountLocked),

                    // net_paid is derived (gross − tax − insurance) so it can never
                    // drift — the model re-enforces it on every write; this is a
                    // live UX preview only.
                    TextInput::make('net_paid')
                        ->label(__('admin.fields.net_paid'))
                        ->prefix('EGP')
                        ->numeric()
                        ->readOnly()
                        ->dehydrated()
                        ->default(0),
                ]),
        ]);
    }

    protected static function syncNet(Set $set, Get $get): void
    {
        $set('net_paid', round(
            (float) ($get('gross_salaries') ?? 0)
                - (float) ($get('salary_tax') ?? 0)
                - (float) ($get('social_insurance') ?? 0),
            2,
        ));
    }
}
