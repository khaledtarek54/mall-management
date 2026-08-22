<?php

namespace App\Filament\Admin\Resources\PayrollRates\Tables;

use App\Filament\Admin\Resources\PayrollRates\PayrollRateResource;
use App\Models\PayrollRate;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class PayrollRatesTable
{
    public static function configure(Table $table): Table
    {
        // Resolved ONCE per render, not per row: a closure that queried would N+1 a table whose
        // whole purpose is to be short and read at a glance.
        $inForce = self::inForceDate();

        return $table
            // No search box. Every column is a number or a date and there is one row a year, so a
            // box here could never match anything an operator typed — which reads as "no results"
            // rather than as "not searchable". Matches the SearchPolicy exemption.
            ->searchable(false)
            // Newest first — the rung an accountant came to supersede is the one at the top.
            ->defaultSort('effective_from', 'desc')
            ->columns([
                TextColumn::make('effective_from')
                    ->label(__('admin.fields.effective_from'))
                    ->date()
                    ->sortable(),

                // Which row the next payroll actually computes on. Derived at render, never stored:
                // "in force" is a function of TODAY, so a column would go stale on a day when
                // nothing happened — the rule `ProjectedState` exists to enforce.
                IconColumn::make('in_force')
                    ->label(__('admin.payroll_rates_screen.in_force'))
                    ->boolean()
                    ->state(fn (PayrollRate $record): bool => $inForce !== null
                        && $record->effective_from->toDateString() === $inForce),

                TextColumn::make('insurable_wage_floor')
                    ->label(__('admin.fields.insurable_wage_floor'))
                    ->money(config('app.currency', 'EGP'))
                    ->placeholder(__('admin.payroll_rates_screen.no_band')),

                TextColumn::make('insurable_wage_ceiling')
                    ->label(__('admin.fields.insurable_wage_ceiling'))
                    ->money(config('app.currency', 'EGP'))
                    ->placeholder(__('admin.payroll_rates_screen.no_band')),

                TextColumn::make('employee_social_insurance_rate')
                    ->label(__('admin.fields.employee_social_insurance_rate'))
                    ->suffix('%'),

                TextColumn::make('employer_social_insurance_rate')
                    ->label(__('admin.fields.employer_social_insurance_rate'))
                    ->suffix('%'),

                TextColumn::make('salary_tax_rate')
                    ->label(__('admin.fields.salary_tax_rate'))
                    ->suffix('%'),

                TextColumn::make('note')
                    ->label(__('admin.fields.note'))
                    ->wrap()
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                // Gated in BOTH, per the project invariant: visible() is the UI, authorize() is the
                // gate. Editing a rung in force is deliberately allowed — an approved payroll froze
                // its own amounts, so a correction changes what is computed NEXT and nothing that
                // has been computed.
                EditAction::make()
                    ->visible(fn (PayrollRate $record) => PayrollRateResource::canEdit($record))
                    ->authorize(fn (PayrollRate $record) => PayrollRateResource::canEdit($record)),
                DeleteAction::make()
                    ->visible(fn () => PayrollRateResource::canDeleteAny())
                    ->authorize(fn () => PayrollRateResource::canDeleteAny()),
            ]);
    }

    /** The `effective_from` of the rung in force today, or null when the ladder starts later. */
    private static function inForceDate(): ?string
    {
        $raw = PayrollRate::query()
            ->where('effective_from', '<=', now()->toDateString())
            ->max('effective_from');

        // `max()` comes back as the driver's raw value — a plain string on both drivers here, and
        // dated `Y-m-d H:i:s` on some. Normalise rather than assume, since the comparison above is
        // against a cast model attribute.
        return $raw === null ? null : substr((string) $raw, 0, 10);
    }
}
