<?php

namespace App\Filament\Imports;

use App\Filament\Imports\Concerns\ResolvesVisibleAssetByCode;
use App\Models\Department;
use App\Models\Employee;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * Load the payroll register at cut-over.
 *
 * Payroll is the one master file an operator cannot type in on the morning of go-live: a mall runs
 * dozens of staff across security, cleaning, technical and admin, and the first run has to be
 * complete or the month's salary expense and the social-insurance withholding are both wrong.
 *
 * **`base_salary` is required, and blank is refused rather than defaulted to zero.** A payslip
 * generated from a zero salary is a document that looks correct and pays nobody — the same class of
 * silent-zero as `opening_accumulated_depreciation` on the fixed-asset register. If the operator
 * genuinely does not know a salary yet, the row belongs out of the file until they do.
 *
 * **`national_id` is the identity, not the name.** Two staff share a name eventually, and a re-import
 * that matched on name would merge them — one employee record, one salary, one person unpaid. Where
 * a national id is absent the code is used instead, and a row with neither creates a new record
 * every time it is imported, which is stated here rather than discovered.
 *
 * Property-scoped through `ResolvesVisibleAssetByCode`, like every other importer: an import
 * bypasses the Create/Edit pages where `assertAssetInScope()` runs, so without the clamp a
 * restricted user could upload another mall's code and staff that mall's payroll.
 */
class EmployeeImporter extends Importer
{
    use ResolvesVisibleAssetByCode;

    protected static ?string $model = Employee::class;

    /** @return array<int, ImportColumn> */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('asset_code')
                ->label('Property code')
                ->requiredMapping()
                ->rules(['required', 'string'])
                ->fillRecordUsing(function (Employee $record, string $state): void {
                    $asset = self::resolveVisibleAsset($state);

                    if ($asset === null) {
                        // Refused, not skipped: staffing the wrong mall's payroll is a money error,
                        // and a silently dropped row reads as "imported" in the success count.
                        throw new \RuntimeException("Unknown or out-of-scope property code [{$state}].");
                    }

                    $record->asset_id = $asset->id;
                }),

            ImportColumn::make('code')
                ->label('Employee code')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:32']),

            ImportColumn::make('name')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:255']),

            ImportColumn::make('national_id')
                ->label('National ID (الرقم القومي)')
                ->rules(['nullable', 'string', 'max:32']),

            ImportColumn::make('department')
                ->label('Department name')
                ->fillRecordUsing(function (Employee $record, ?string $state): void {
                    if (blank($state)) {
                        return;
                    }

                    // Matched, never created. A typo would otherwise quietly open a second
                    // "Securty" department and split the payroll register across both.
                    $record->department_id = Department::query()
                        ->where('name', trim($state))
                        ->where(fn ($q) => $q->whereNull('asset_id')->orWhere('asset_id', $record->asset_id))
                        ->value('id');
                }),

            ImportColumn::make('position')
                ->rules(['nullable', 'string', 'max:255']),

            ImportColumn::make('hire_date')
                ->label('Hire date (YYYY-MM-DD)')
                ->requiredMapping()
                // NOT NULL in the schema, and rightly so: it dates the employment the payroll and
                // any end-of-service calculation both rest on. A blank cannot be defaulted to today
                // without inventing a start date for somebody who has worked here for years.
                ->rules(['required', 'date']),

            ImportColumn::make('base_salary')
                ->label('Base salary (EGP/month)')
                ->requiredMapping()
                ->numeric()
                // Required, and no default. A zero salary generates a payslip that looks right and
                // pays nobody.
                ->rules(['required', 'numeric', 'min:0.01']),

            ImportColumn::make('payment_method')
                ->rules(['nullable', 'string', 'max:32']),

            ImportColumn::make('phone')
                ->rules(['nullable', 'string', 'max:32']),

            ImportColumn::make('status')
                ->rules(['nullable', 'string', 'max:32'])
                ->fillRecordUsing(function (Employee $record, ?string $state): void {
                    $record->status = blank($state) ? 'active' : trim($state);
                }),

            ImportColumn::make('notes')
                ->rules(['nullable', 'string']),
        ];
    }

    public function resolveRecord(): ?Employee
    {
        $asset = self::resolveVisibleAsset(
            is_string($this->data['asset_code'] ?? null) ? $this->data['asset_code'] : null
        );

        if ($asset === null) {
            return new Employee;
        }

        // Identity is the national id where there is one, the employee code otherwise — never the
        // name. Two people share a name eventually, and merging them leaves one of them unpaid.
        $nationalId = trim((string) ($this->data['national_id'] ?? ''));
        $code = trim((string) ($this->data['code'] ?? ''));

        if ($nationalId !== '') {
            return Employee::firstOrNew(['asset_id' => $asset->id, 'national_id' => $nationalId]);
        }

        if ($code !== '') {
            return Employee::firstOrNew(['asset_id' => $asset->id, 'code' => $code]);
        }

        return new Employee;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your employee import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
