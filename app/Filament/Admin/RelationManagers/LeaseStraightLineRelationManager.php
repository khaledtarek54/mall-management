<?php

namespace App\Filament\Admin\RelationManagers;

use App\Filament\Admin\RelationManagers\Concerns\CountsItsRows;
use App\Models\Lease;
use App\Services\StraightLineRentService;
use Carbon\CarbonImmutable;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * What this lease BILLS against what the books RECOGNISE.
 *
 * Under EAS 49 / IFRS 16 an operating lease's income is recognised **straight-line over the term**:
 * escalating rents and rent-free periods are financing and incentive, not a change in the pattern of
 * benefit. So a lease rising 7% a year recognises its AVERAGE rent every month from day one — more
 * than it bills early, less than it bills late — and the difference accrues as an asset that unwinds
 * to zero by expiry.
 *
 * **Why this class exists.** The engine (`StraightLineRentService`), its journalizer and its
 * scheduled command all shipped; the visibility layer never did. A lease's straight-line position —
 * a registered GL posting source — was reachable only by running a CLI command, so the number the
 * owner's accountant asks about first appeared on no screen at all (found by sweeping the lease page
 * for unreachable functionality, 2026-08-18).
 *
 * Shown only when the feature is ON and the lease can actually be straight-lined. A permanently
 * empty tab reads as "nothing has happened here" rather than "this does not apply", which is the
 * same reason the percentage-rent tabs are conditional.
 */
class LeaseStraightLineRelationManager extends RelationManager
{
    use CountsItsRows;

    protected static string $relationship = 'straightLineAdjustments';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.straight_line.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        if (! $ownerRecord instanceof Lease) {
            return false;
        }

        $service = app(StraightLineRentService::class);

        // Both conditions, and neither is redundant: the setting says the operator recognises rent
        // this way at all, and the schedule says THIS lease can be — a lease with no term or no rent
        // ladder cannot be averaged, and averaging a term the system does not know the end of would
        // be worse than recognising nothing.
        return $service->enabled() && $service->scheduleFor($ownerRecord) !== null;
    }

    public function table(Table $table): Table
    {
        $lease = $this->lease();
        $schedule = app(StraightLineRentService::class)->scheduleFor($lease);

        return $table
            // The schedule ABOVE the rows, because the rows are its consequence. Without it the
            // table is a list of adjustments with nothing to explain where they came from.
            ->description($schedule === null ? null : __('admin.straight_line.schedule', [
                'monthly' => 'EGP '.number_format($schedule['monthly'], 2),
                'total' => 'EGP '.number_format($schedule['total'], 2),
                'months' => $schedule['months'],
                'from' => $schedule['from']->format('m/Y'),
                'to' => $schedule['to']->format('m/Y'),
            ]))
            // No search box: every row is a period and three amounts on ONE lease, the set is a
            // dozen rows the reader can already see, and the model carries no `search_text` blob —
            // so the box Filament renders by default could never match anything typed into it.
            // `SearchPolicyConformanceTest` fails the build on exactly that.
            ->searchable(false)
            ->columns([
                TextColumn::make('period')
                    ->label(__('admin.fields.period'))
                    ->formatStateUsing(fn ($state) => CarbonImmutable::parse($state)
                        ->locale(app()->getLocale())->isoFormat('MMM YYYY'))
                    ->sortable(),
                TextColumn::make('billed_amount')
                    ->label(__('admin.straight_line.billed'))
                    ->money('EGP')
                    ->alignRight(),
                TextColumn::make('straight_line_amount')
                    ->label(__('admin.straight_line.recognised'))
                    ->money('EGP')
                    ->alignRight(),
                // The whole point of the tab: positive means the books recognise MORE than was
                // billed this month (early in an escalating term), negative means less. It sums to
                // zero over the life of the lease, which is the property an accountant checks.
                TextColumn::make('adjustment_amount')
                    ->label(__('admin.straight_line.adjustment'))
                    ->money('EGP')
                    ->alignRight()
                    ->weight('bold')
                    ->color(fn ($state) => (float) $state > 0 ? 'success' : ((float) $state < 0 ? 'warning' : 'gray'))
                    ->summarize(Sum::make()
                        ->label(__('admin.straight_line.cumulative'))
                        ->money('EGP')),
                TextColumn::make('entry_date')
                    ->label(__('admin.fields.entry_date'))
                    ->date('d/m/Y')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('period')
            ->emptyStateHeading(__('admin.straight_line.empty_heading'))
            ->emptyStateDescription(__('admin.straight_line.empty_body'))
            // Read-only: an adjustment is POSTED by `accounting:post-straight-line-rent`, which
            // derives it from the schedule and the month's billing. A create button here would be a
            // second way to state a number the engine already computes.
            ->recordActions([])
            ->headerActions([]);
    }

    /** `getOwnerRecord()` returns the base `Model`; narrowed once so the service call type-checks. */
    protected function lease(): Lease
    {
        /** @var Lease $lease */
        $lease = $this->getOwnerRecord();

        return $lease;
    }
}
