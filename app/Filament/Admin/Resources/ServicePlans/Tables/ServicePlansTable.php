<?php

namespace App\Filament\Admin\Resources\ServicePlans\Tables;

use App\Filament\Admin\Actions\ServicePlanActions;
use App\Filament\Admin\Resources\ServicePlans\ServicePlanResource;
use App\Models\ServicePlan;
use App\Models\Trade;
use App\Services\GeneratePreventiveWorkOrdersService;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ServicePlansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn ($query) => $query->with(['asset', 'unit', 'equipment', 'trade'])->withComplianceCounts())
            ->columns([
                TextColumn::make('title')
                    ->label(__('admin.facility.fields.title'))
                    ->weight('bold')
                    ->description(fn (ServicePlan $record) => $record->trade?->label() ?? '—')
                    ->searchable(),
                TextColumn::make('asset.name')
                    ->label(__('admin.facility.fields.property'))
                    ->badge()->color('gray')->toggleable(),
                TextColumn::make('plan_type')
                    ->label(__('admin.facility.fields.plan_type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.facility.plan_types.{$state}"))
                    ->color(fn (string $state) => $state === ServicePlan::MAINTENANCE_TYPE_FIXED ? 'info' : 'gray')
                    ->toggleable(),
                TextColumn::make('equipment.code')
                    ->label(__('admin.facility.equipment.singular'))
                    ->fontFamily('mono')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('unit.code')
                    ->label(__('admin.facility.fields.unit'))
                    ->placeholder('—'),
                // Where an area-based round runs (cleaning, landscaping) — blank for equipment work.
                TextColumn::make('area.name')
                    ->label(__('admin.facility.fields.area'))
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('frequency')
                    ->label(__('admin.facility.fields.frequency'))
                    ->state(fn (ServicePlan $record) => $record->frequency_value.' '.__("admin.facility.frequency_units.{$record->frequency_unit}")),
                TextColumn::make('next_due_date')
                    ->label(__('admin.facility.fields.next_due'))
                    ->date('d/m/Y')
                    ->sortable()
                    // Highlight overdue plans.
                    ->color(fn ($state) => $state && $state->isPast() ? 'danger' : 'success')
                    // A stuck plan looks EXACTLY like an overdue one — the date sits in the past
                    // either way — so the date alone sends the operator chasing a technician for a
                    // round the system never asked anybody to do. The reason belongs next to it.
                    ->icon(fn (ServicePlan $record) => $record->generationIsFailing() ? 'heroicon-m-exclamation-triangle' : null)
                    ->description(fn (ServicePlan $record) => $record->generationIsFailing()
                        ? __('admin.facility.generation_failing', ['reason' => (string) $record->last_generation_error])
                        : null),
                // **Which plans are we failing?** One portfolio percentage tells an operator
                // nothing they can act on; "the generator monthly test-run is 40%" names the thing
                // to fix. Counted in the list query, never per row.
                TextColumn::make('compliance')
                    ->label(__('admin.facility.fields.pm_compliance'))
                    ->badge()
                    ->state(fn (ServicePlan $r): ?string => ($rate = $r->complianceRateFromCounts()) === null
                        ? null
                        : $rate.'%')
                    // A plan whose cycles have not settled yet has no compliance — 0% and 100%
                    // would both be inventions.
                    ->placeholder(__('admin.facility.pm_compliance.no_history'))
                    ->color(fn (ServicePlan $r): ?string => match (true) {
                        $r->complianceRateFromCounts() === null => 'gray',
                        $r->complianceRateFromCounts() >= 90 => 'success',
                        $r->complianceRateFromCounts() >= 70 => 'warning',
                        default => 'danger',
                    })
                    ->description(fn (ServicePlan $r): ?string => $r->complianceRateFromCounts() === null
                        ? null
                        : __('admin.facility.pm_compliance.breakdown', [
                            'on_time' => (int) $r->pm_on_time_count,
                            'late' => (int) $r->pm_late_count,
                            'overdue' => (int) $r->pm_overdue_count,
                        ])),

                IconColumn::make('is_active')
                    ->label(__('admin.facility.fields.active'))
                    ->boolean(),
            ])
            // A plan that silently stopped generating (machine moved/retired, or deactivated) was
            // unfindable — the table had no filters at all, and ActionRequired surfaces breached
            // work ORDERS, not stale plans. These make an overdue/inactive plan visible.
            ->filters([
                // The trade is the routing spine and the axis every maintenance-spend report
                // groups by — and it left the search blob when it stopped being a column on the
                // row (the blob is a pure function of a row's OWN attributes, so reaching through
                // to the trade's name would strand every blob the day a trade is renamed). A
                // 14-value dimension belongs in a filter anyway: search finds A RECORD, a filter
                // narrows A SET.
                SelectFilter::make('trade_id')
                    ->label(__('admin.facility.fields.trade'))
                    ->options(fn () => Trade::options(activeOnly: false)),

                TernaryFilter::make('is_active')
                    ->label(__('admin.facility.filters.active')),
                Filter::make('overdue')
                    ->label(__('admin.facility.filters.overdue'))
                    ->query(fn ($query) => $query->where('is_active', true)->whereDate('next_due_date', '<', now()->toDateString())),
                // Overdue and STUCK are different problems with the same symptom: one needs a
                // technician, the other needs somebody to fix the plan. Filtering them apart is the
                // difference between a backlog and a system that quietly stopped.
                Filter::make('generation_failing')
                    ->label(__('admin.facility.filters.generation_failing'))
                    ->query(fn ($query) => $query->whereNotNull('last_generation_failed_at')),
            ])
            // **The producer had no trigger.** This screen already says a plan is OVERDUE and shows
            // the error when generation is FAILING — and offered nothing to do about either. The
            // only remedies were waiting for tonight's cron or opening a shell. CAM's pool and a
            // lease's billing both put the same act behind a button (2026-08-18).
            ->headerActions([
                Action::make('generateDue')
                    ->label(__('admin.facility.generate_due'))
                    ->icon('heroicon-o-play')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('admin.facility.generate_due_confirm'))
                    ->visible(fn (): bool => auth()->user()?->can('facility.create') ?? false)
                    ->authorize(fn (): bool => auth()->user()?->can('facility.create') ?? false)
                    ->action(function (): void {
                        abort_unless(auth()->user()?->can('facility.create') ?? false, 403);

                        $service = app(GeneratePreventiveWorkOrdersService::class);
                        $created = $service->run();

                        ServicePlanActions::report($created, $service->failures);
                    }),
            ])
            ->recordActions([

                // Read the record without opening its edit form — less
                // friction, and no write surface for view-only roles. The
                // schema is the resource's own form rendered disabled, so it
                // cannot drift from the fields that actually exist.
                ViewAction::make()
                    ->visible(fn ($record) => ServicePlanResource::canView($record))
                    ->authorize(fn ($record) => ServicePlanResource::canView($record)),
                // ── The list FINDS; the record ACTS ─────────────────────────────────────
                // Defined once in App\Filament\Admin\Actions\ServicePlanActions and composed onto this
                // record's own page, so opening the record is enough to act on it.
                EditAction::make()->visible(fn (ServicePlan $record) => ServicePlanResource::canEdit($record)),
            ])
            ->defaultSort('next_due_date')
            ->emptyStateIcon('heroicon-o-calendar')
            ->emptyStateHeading(__('admin.empty.service_plans.heading'))
            ->emptyStateDescription(__('admin.empty.service_plans.description'))
            ->emptyStateActions([
                CreateAction::make()
                    ->label(__('admin.empty.service_plans.cta'))
                    ->icon('heroicon-o-plus'),
            ]);
    }
}
