<?php

namespace App\Filament\Imports;

use App\Models\MeterReading;
use App\Models\UtilityMeter;
use App\Support\TenantScope;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

/**
 * Load meter readings — the opening register at cut-over, and the monthly round thereafter.
 *
 * A mall reads dozens of sub-meters a month and they arrive as a spreadsheet from the technical
 * team. Typing them one at a time is where the operator gives up and starts recharging utilities
 * off a side sheet, which is precisely the practice this module replaced.
 *
 * **Cost derives from the meter's tariff, and a supplied cost is treated as an override.** Consumption
 * × `rate_per_unit` is the same rule the readings form applies, kept here rather than re-implemented
 * so an imported reading and a typed one recharge identically. Where the meter carries no tariff and
 * the file carries no cost, the reading imports with cost 0 and simply cannot be billed —
 * `BillMeterReadingService` refuses a zero-cost recharge — which is the safe direction: a reading
 * recorded and not billed is recoverable, a reading billed at a guessed price is a credit note and a
 * phone call.
 *
 * **A BILLED reading is never overwritten.** Once a reading has raised a recharge invoice it is the
 * evidence for that invoice; re-importing the month would otherwise change the figure underneath a
 * document already sent to the tenant. Those rows are refused by name so the operator sees which.
 *
 * Meters are identified by `meter_number`, scoped to the properties the importer can see — a reading
 * uploaded against another mall's meter would recharge that mall's tenant.
 */
class MeterReadingImporter extends Importer
{
    protected static ?string $model = MeterReading::class;

    /** @return array<int, ImportColumn> */
    public static function getColumns(): array
    {
        return [
            ImportColumn::make('meter_number')
                ->label('Meter number')
                ->requiredMapping()
                ->rules(['required', 'string', 'max:64'])
                // A lookup key, not a column on the reading — `resolveRecord()` has already turned
                // it into `utility_meter_id`. Without the no-op fill, Filament writes it as an
                // attribute and the insert fails on a column that does not exist.
                ->fillRecordUsing(fn (): null => null),

            ImportColumn::make('reading_date')
                ->label('Reading date (YYYY-MM-DD)')
                ->requiredMapping()
                ->rules(['required', 'date']),

            ImportColumn::make('reading_value')
                ->label('Meter face value')
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'numeric', 'min:0']),

            ImportColumn::make('consumption')
                ->label('Consumption for the period')
                ->requiredMapping()
                ->numeric()
                ->rules(['required', 'numeric', 'min:0']),

            ImportColumn::make('cost')
                ->label('Cost (EGP) — leave blank to derive from the meter tariff')
                ->numeric()
                ->rules(['nullable', 'numeric', 'min:0'])
                ->fillRecordUsing(function (MeterReading $record, mixed $state): void {
                    if ($state !== null && $state !== '') {
                        $record->cost = round((float) $state, 2);

                        return;
                    }

                    // Same rule as the readings form: consumption × tariff. A meter with no tariff
                    // leaves 0, and BillMeterReadingService refuses to raise a zero-cost recharge —
                    // so the reading is on file and visibly unbillable rather than billed at a guess.
                    $rate = (float) ($record->meter->rate_per_unit ?? 0);
                    $record->cost = $rate > 0
                        ? round((float) $record->consumption * $rate, 2)
                        : 0.0;
                }),

            ImportColumn::make('notes')
                ->rules(['nullable', 'string']),
        ];
    }

    public function resolveRecord(): ?MeterReading
    {
        $meterNumber = trim((string) ($this->data['meter_number'] ?? ''));
        $date = trim((string) ($this->data['reading_date'] ?? ''));

        // NULL from `visibleAssetIds()` means unrestricted (super_admin), not "no properties" —
        // passing it to whereIn() is a TypeError, and treating it as [] would refuse every row for
        // the one user allowed to import anything.
        $visible = TenantScope::visibleAssetIds();

        $meter = UtilityMeter::query()
            ->where('meter_number', $meterNumber)
            ->when($visible !== null, fn ($q) => $q->whereIn('asset_id', $visible))
            ->first();

        if ($meter === null) {
            throw new \RuntimeException("Unknown or out-of-scope meter number [{$meterNumber}].");
        }

        // One reading per meter per date, so re-uploading a corrected sheet updates rather than
        // doubling the month's consumption.
        $existing = MeterReading::query()
            ->where('utility_meter_id', $meter->id)
            ->whereDate('reading_date', $date)
            ->first();

        if ($existing !== null && $existing->isBilled()) {
            throw new \RuntimeException(
                "Reading for meter [{$meterNumber}] on {$date} has already been billed — cancel its recharge invoice before re-importing."
            );
        }

        $reading = $existing ?? new MeterReading;
        $reading->utility_meter_id = $meter->id;
        // Set eagerly so the cost column's tariff lookup has a meter to read on a NEW row.
        $reading->setRelation('meter', $meter);

        return $reading;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your meter-reading import has completed and '.number_format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' '.number_format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
