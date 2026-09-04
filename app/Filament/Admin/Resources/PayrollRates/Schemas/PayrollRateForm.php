<?php

namespace App\Filament\Admin\Resources\PayrollRates\Schemas;

use Closure;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Validation\Rule;

class PayrollRateForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.payroll_rates_screen.sections.period'))
                ->components([
                    TextInput::make('effective_from')
                        ->label(__('admin.fields.effective_from'))
                        ->type('date')
                        ->required()
                        // One rung per day. A second row on the same date makes "which one is in
                        // force" unanswerable, and the resolver would silently pick by insertion
                        // order — the shape `tax_rates` refuses for the same reason.
                        ->rules([fn ($record) => Rule::unique('payroll_rates', 'effective_from')->ignore($record?->id)])
                        ->helperText(__('admin.payroll_rates_screen.help.effective_from')),

                    TextInput::make('note')
                        ->label(__('admin.fields.note'))
                        ->maxLength(255)
                        ->hintIcon(Heroicon::OutlinedQuestionMarkCircle, __('admin.hints.payroll_rate_note')),
                ]),

            Section::make(__('admin.payroll_rates_screen.sections.band'))
                ->description(__('admin.payroll_rates_screen.sections.band_description'))
                ->columns(2)
                ->components([
                    TextInput::make('insurable_wage_floor')
                        ->label(__('admin.fields.insurable_wage_floor'))
                        ->numeric()->minValue(0)
                        ->prefix(config('app.currency', 'EGP'))
                        ->helperText(__('admin.payroll_rates_screen.help.floor')),

                    TextInput::make('insurable_wage_ceiling')
                        ->label(__('admin.fields.insurable_wage_ceiling'))
                        ->numeric()->minValue(0)
                        ->prefix(config('app.currency', 'EGP'))
                        // Refused rather than clamped: a ceiling below the floor would make every
                        // insurable wage the ceiling, silently, on every payslip.
                        //
                        // **This was `->rules(['gte:insurable_wage_floor'])`, and it refused every
                        // ceiling there has ever been.** A rule string reaches the validator
                        // verbatim (measured: `TextInput::make('insurable_wage_ceiling')->numeric()
                        // ->minValue(0)->rules([...])->getValidationRules()` returns
                        // `['nullable','numeric','min:0','gte:insurable_wage_floor']`), while the
                        // attribute it is keyed under is `data.insurable_wage_ceiling` — so
                        // `Validator::getValue('insurable_wage_floor')` looked at the ROOT of the
                        // Livewire payload and found nothing. `validateGte()` then falls to
                        // `isSameType('16700', null)`, which is false. Measured against that exact
                        // rules array: the shipped 2,700 / 16,700 band FAILS, so Egypt's own
                        // insurable-wage band could not be entered through its own screen.
                        //
                        // Filament's `->gte('insurable_wage_floor')` would resolve the path, and
                        // still refuse the second case: **a null bound is NO bound**
                        // (`PayrollRates::insurableWage()` skips a null floor entirely — a
                        // ceiling-only rung is a legal, meaningful rung), while `validateGte()`
                        // answers false against a null comparison value. So the rule is stated
                        // here, where both halves are visible at once.
                        ->rules([
                            fn (Get $get): Closure => function (string $attribute, $value, Closure $fail) use ($get): void {
                                $floor = $get('insurable_wage_floor');

                                if (blank($value) || blank($floor)) {
                                    return;
                                }

                                if ((float) $value < (float) $floor) {
                                    $fail(__('admin.validation.insurable_ceiling_below_floor'));
                                }
                            },
                        ])
                        ->helperText(__('admin.payroll_rates_screen.help.ceiling')),
                ]),

            Section::make(__('admin.payroll_rates_screen.sections.rates'))
                ->description(__('admin.payroll_rates_screen.sections.rates_description'))
                ->columns(3)
                ->components([
                    TextInput::make('employee_social_insurance_rate')
                        ->label(__('admin.fields.employee_social_insurance_rate'))
                        ->suffix('%')->numeric()->minValue(0)->maxValue(100)->default(0)->required()
                        ->helperText(__('admin.payroll_rates_screen.help.employee_si')),

                    TextInput::make('employer_social_insurance_rate')
                        ->label(__('admin.fields.employer_social_insurance_rate'))
                        ->suffix('%')->numeric()->minValue(0)->maxValue(100)->default(0)->required()
                        ->helperText(__('admin.payroll_rates_screen.help.employer_si')),

                    TextInput::make('salary_tax_rate')
                        ->label(__('admin.fields.salary_tax_rate'))
                        ->suffix('%')->numeric()->minValue(0)->maxValue(100)->default(0)->required()
                        ->helperText(__('admin.payroll_rates_screen.help.salary_tax')),
                ]),
        ]);
    }
}
