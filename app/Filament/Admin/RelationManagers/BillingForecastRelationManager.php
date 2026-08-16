<?php

namespace App\Filament\Admin\RelationManagers;

use App\Models\ChargeCode;
use App\Models\Lease;
use App\Services\LeaseBillingForecastService;
use Carbon\CarbonImmutable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

/**
 * **What this lease will be invoiced, period by period** — beside the Charge schedule, because the
 * two answer the questions people keep confusing.
 *
 * The Charge schedule holds the RULES: one dated row per amount, since storing the months as well as
 * the rule would store the same fact twice. It is therefore read as a payment plan and found
 * wanting — *"why doesn't it show what's paid each month?"* — which is a fair complaint about a
 * missing screen rather than about that one. This is the missing screen, one tab away, so the
 * comparison an operator wants to make is a click and not a mental exercise.
 *
 * **It computes nothing.** Every row is `MonthlyBillingService::planInvoiceForLease()` by way of
 * `LeaseBillingForecastService` — the same method the real run persists and the preview renders. A
 * forecast with its own arithmetic diverges first on a proration edge, a cycle boundary or an
 * escalation step, and does it silently.
 *
 * **Why a relation manager with no relation.** Filament needs `$relationship` named to mount one,
 * but `Table::records()` sets a data source and `Table::hasQuery()` is `! $dataSource` — so the
 * relation below is **never queried**. `charges` is named because that is genuinely what these rows
 * are a projection of; nothing reads it. Registered like every other tab, so it inherits the panel's
 * authorization and property scoping rather than growing its own.
 */
class BillingForecastRelationManager extends RelationManager
{
    /** Never queried — see the class docblock. The table's data source replaces it entirely. */
    protected static string $relationship = 'charges';

    protected static ?string $title = null;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.forecast.tab');
    }

    /**
     * Read-only by construction: a forecast is a reading of the schedule, and the way to change it
     * is to change the schedule.
     */
    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->rows())
            ->columns([
                TextColumn::make('period')
                    ->label(__('admin.forecast.period'))
                    ->weight('medium'),
                TextColumn::make('lines')
                    ->label(__('admin.forecast.lines'))
                    ->html()
                    ->size('xs')
                    ->color('gray'),
                TextColumn::make('net')
                    ->label(__('admin.forecast.net'))
                    ->alignEnd(),
                TextColumn::make('vat')
                    ->label(__('admin.tables.invoice.vat'))
                    ->alignEnd(),
                TextColumn::make('total')
                    ->label(__('admin.forecast.total'))
                    ->alignEnd()
                    ->weight('bold'),
                TextColumn::make('status')
                    ->label(__('admin.tables.common.status'))
                    ->badge()
                    ->color(fn (array $record): string => $record['status_color']),
            ])
            // A relation manager's base table wires `recordAction`/`recordUrl` closures typed
            // `Model $record` — they exist to open the related record, and these rows are computed
            // periods with nothing to open. Left in place they fatal on the first render.
            ->recordAction(null)
            ->recordUrl(null)
            ->paginated(false)
            ->heading(__('admin.forecast.tab'))
            ->description(fn (): ?string => $this->summary())
            ->emptyStateHeading(__('admin.forecast.empty'));
    }

    /** @return array<int, array<string, mixed>> */
    protected function rows(): array
    {
        $forecast = $this->forecast();
        $rows = [];

        foreach ($forecast['rows'] as $index => $row) {
            $billed = $row['invoice_number'] !== null;

            $rows[$index] = [
                // Filament keys an array data source by the outer array key; `id` keeps the row
                // identifiable to anything that later wants to act on one.
                'id' => $index,
                'period' => self::periodLabel($row),
                // The charge TYPE, translated — not the planner's `description`, which embeds an
                // English month name ("Base Rent - Oct–Dec 2026") because it is written once onto an
                // invoice line and never re-read. Repeating the period here would duplicate this
                // row's own first column anyway.
                'lines' => new HtmlString(collect($row['items'])
                    ->map(fn (array $item): string => e(ChargeCode::labelFor($item['type']))
                        .' — '.number_format((float) $item['amount'], 2))
                    ->join('<br>') ?: '—'),
                'net' => $row['billable'] ? number_format((float) $row['subtotal'], 2) : '—',
                'vat' => $row['billable'] && (float) $row['vat_amount'] > 0
                    ? number_format((float) $row['vat_amount'], 2)
                    : '—',
                'total' => $row['billable'] ? number_format((float) $row['total'], 2) : '—',
                'status' => match (true) {
                    $billed => __('admin.forecast.status_invoiced', ['number' => $row['invoice_number']]),
                    ! $row['billable'] => __('admin.billing_preview.reason.'.($row['reason'] ?? 'unknown')),
                    default => __('admin.forecast.status_forecast'),
                },
                'status_color' => match (true) {
                    $billed => 'success',
                    ! $row['billable'] => 'gray',
                    default => 'info',
                },
            ];
        }

        return $rows;
    }

    /** The window, the count, the money — and any caveat that changes how the rows should be read. */
    protected function summary(): ?string
    {
        $forecast = $this->forecast();

        $locale = app()->getLocale();

        $parts = [__('admin.forecast.window_value', [
            'from' => $forecast['from']->locale($locale)->isoFormat('MMM YYYY'),
            'to' => $forecast['to']->locale($locale)->isoFormat('MMM YYYY'),
            'count' => count($forecast['rows']),
            'total' => 'EGP '.number_format($forecast['total'], 2),
        ])];

        // A draft or pending lease bills nothing until it is activated: `isBillableForPeriod()`
        // refuses on status, while the planner behind these rows answers "what WOULD this bill" —
        // the right question during a negotiation, and a misleading one left unlabelled.
        if (! $forecast['lease_is_active']) {
            $parts[] = __('admin.forecast.not_active');
        }

        if ($forecast['truncated']) {
            $parts[] = __('admin.forecast.truncated', ['count' => count($forecast['rows'])]);
        }

        return implode(' · ', $parts);
    }

    /** One computation per render, shared by the rows and the summary. */
    protected function forecast(): array
    {
        /** @var Lease $lease */
        $lease = $this->getOwnerRecord();

        return $this->cachedForecast ??= app(LeaseBillingForecastService::class)->forecast($lease);
    }

    /** @var array<string, mixed>|null */
    protected ?array $cachedForecast = null;

    /**
     * `Oct–Dec 2026` for a cycle, `November 2026` for a single month — **in the reader's language**.
     *
     * `format()` emits English month names whatever the locale is set to; `isoFormat()` on a
     * localised instance is the panel's idiom for a month-and-year, and the one an Arabic reader
     * needs here. The comparison that decides single-month vs cycle stays on the numeric `Y-m`, so
     * it cannot change behaviour with the language.
     */
    protected static function periodLabel(array $row): string
    {
        $locale = app()->getLocale();
        $start = CarbonImmutable::instance($row['period_start'])->locale($locale);
        $end = CarbonImmutable::instance($row['period_end'])->locale($locale);

        return $start->format('Y-m') === $end->format('Y-m')
            ? $start->isoFormat('MMMM YYYY')
            : $start->isoFormat('MMM').'–'.$end->isoFormat('MMM YYYY');
    }
}
