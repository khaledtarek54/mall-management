<?php

namespace App\Filament\Admin\Resources\Payrolls\Schemas;

use App\Models\Payroll;
use App\Support\Filament\BankAccountField;
use App\Support\Filament\MonthPicker;
use App\Support\Filament\PropertyField;
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

                    PropertyField::make(alsoDisabledWhen: $locked)
                        ->searchable()
                        ->preload(),

                    // A payroll run is FOR a month — the day was never part of the answer, and a
                    // calendar of days asks the operator to invent one.
                    MonthPicker::make('period_month')
                        ->label(__('admin.fields.payroll_month'))
                        ->required()
                        ->default(now()->startOfMonth()->toDateString())
                        ->monthsBack(36)
                        ->monthsAhead(1)
                        ->disabled($locked),

                    Select::make('paid_from')
                        ->label(__('admin.fields.paid_from'))
                        ->options(fn () => __('admin.enums.expense_paid_from'))
                        ->default('bank')
                        ->native(false)
                        ->required()
                        ->disabled($locked),

                    // Which bank account this money moved through — optional, and null means the rail
                    // decides. Set it and the posting lands in THAT account's chart account, which is
                    // what lets a mall banking in two places reconcile either one.
                    BankAccountField::make()
                        ->disabled($locked),

                    Textarea::make('description')
                        ->label(__('admin.fields.description'))
                        ->rows(2)
                        ->columnSpanFull()
                        ->disabled($locked),
                ]),

            Section::make(__('admin.sections.amounts'))
                ->description(__('admin.sections.payroll_amounts_hint'))
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
