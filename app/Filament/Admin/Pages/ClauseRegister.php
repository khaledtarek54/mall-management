<?php

namespace App\Filament\Admin\Pages;

use App\Contracts\DeliverableReport;
use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\ExportsReport;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Models\LeaseClause;
use App\Support\Modules;
use App\Support\TenantScope;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Every abstracted lease term in the portfolio, in one list.
 *
 * **The question this exists to answer is written in `LeaseClause`'s own docblock** — the reason
 * the abstract was built at all:
 *
 * > *"nothing can even answer 'how many of our leases have a co-tenancy trigger tied to the anchor
 * > we are about to lose?'"*
 *
 * It still could not. The clauses moved out of the uploaded PDF and into a table, `LeaseClause`
 * grew `contingentMoney()`, `inForceOn()` and `liveExposure()` to answer exactly that — and
 * **`liveExposure()` had no caller anywhere in `app/`**, only tests. Fully built, fully tested and
 * unreachable, the shape this codebase names for the four orphaned services found in August: the
 * green test file is what made it look maintained. Ninety-nine clauses sat on the demo books
 * readable one lease at a time.
 *
 * This page is that caller. It is the leasing counterpart of the rent roll: the rent roll says what
 * each tenancy PAYS, the expiration schedule says when it STOPS, and this says what else the
 * contract obliges either party to.
 *
 * **The exposure count in the subheading is `liveExposure()` verbatim**, not a count reassembled
 * here from filters. That scope bundles three conditions because composing them by hand is how the
 * answer goes wrong — its docblock records a version that reported a TERMINATED lease as exposed,
 * because an open-ended co-tenancy clause reads as in force for ever while the tenancy it protected
 * has ended. A second assembly of that logic on this page would be free to drift back into it.
 */
class ClauseRegister extends Page implements DeliverableReport, HasSchemas, HasTable
{
    use ExportsReport;
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentMagnifyingGlass;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'clause-register';

    public static function canAccess(): bool
    {
        return Modules::enabled('reports') && (Auth::user()?->can('reports.view') ?? false);
    }

    public function getTitle(): string
    {
        return __('admin.clause_register.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.clause_register.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('admin.clause_register.subheading', [
            'clauses' => $this->scoped()->count(),
            // The portfolio question, asked through the model's own scope — see the class docblock.
            'exposed' => trans_choice(
                'admin.clause_register.exposed_leases',
                $this->scoped()->liveExposure()->distinct()->count('lease_id'),
            ),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            ...$this->exportActions(),
        ];
    }

    /**
     * Every clause this operator may see.
     *
     * Property isolation is `#[PropertyOwned(via: 'lease.unit')]` on the model, so the scope is the
     * same two hops — never `currentAssetId()` alone, which is null in All-Properties mode and
     * would return the whole portfolio to a restricted operator.
     *
     * @return Builder<LeaseClause>
     */
    protected function scoped(): Builder
    {
        $ids = TenantScope::reportAssetIds(TenantScope::currentAssetId());

        return LeaseClause::query()
            ->when($ids !== null, fn (Builder $q) => $q->whereHas(
                'lease.unit',
                fn (Builder $unit) => $unit->whereIn('asset_id', $ids),
            ));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn (): Builder => $this->scoped()->with(['lease.tenant', 'lease.unit']))
            ->defaultSort('type')
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.fields.clause_type'))
                    ->multiple()
                    ->options(__('admin.enums.lease_clause_type')),

                // THE headline filter: the two types the benchmark calls contingent money, on
                // leases that are still live. One tick answers the question the register exists for.
                Filter::make('live_exposure')
                    ->label(__('admin.clause_register.live_exposure'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->liveExposure()),

                Filter::make('in_force')
                    ->label(__('admin.clause_register.in_force_today'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->inForceOn(CarbonImmutable::today())),
            ])
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.fields.clause_type'))
                    ->formatStateUsing(fn (LeaseClause $record): string => $record->label())
                    ->badge()
                    // Contingent money is the only distinction worth a colour here: those two can
                    // cost the mall rent, the rest describe how the shop is run.
                    ->color(fn (LeaseClause $record): string => in_array($record->type, LeaseClause::CONTINGENT_MONEY, true)
                        ? 'warning'
                        : 'gray')
                    ->sortable(),
                TextColumn::make('lease.tenant.name')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('lease.unit.code')
                    ->label(__('admin.tables.invoice.unit'))
                    ->sortable(),
                TextColumn::make('lease.reference')
                    ->label(__('admin.tables.lease.reference'))
                    ->searchable()
                    ->toggleable(),
                TextColumn::make('summary')
                    ->label(__('admin.fields.clause_summary'))
                    ->wrap()
                    ->searchable()
                    ->limit(140),
                TextColumn::make('threshold_pct')
                    ->label(__('admin.fields.clause_trigger'))
                    ->state(fn (LeaseClause $record): string => self::trigger($record))
                    ->color('gray'),
                TextColumn::make('applies_to')
                    ->label(__('admin.fields.clause_applies_to'))
                    ->date('d/m/Y')
                    ->placeholder(__('admin.clause_register.open_ended'))
                    ->sortable(),
                TextColumn::make('source_reference')
                    ->label(__('admin.fields.clause_source_reference'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->emptyStateHeading(__('admin.clause_register.empty'))
            ->emptyStateDescription(__('admin.clause_register.empty_description'));
    }

    /**
     * The clause's own number, whichever of the four columns carries it.
     *
     * A co-tenancy clause is a percentage, a kick-out is a sales figure, a radius is kilometres and
     * an assignment is notice days — one column each, and a register that showed only
     * `threshold_pct` would print a blank cell for three types out of four.
     */
    public static function trigger(LeaseClause $record): string
    {
        return match (true) {
            $record->threshold_pct !== null => number_format((float) $record->threshold_pct, 2).'%',
            $record->threshold_amount !== null => 'EGP '.number_format((float) $record->threshold_amount, 2),
            $record->radius_km !== null => number_format((float) $record->radius_km, 2).' km',
            $record->notice_days !== null => __('admin.clause_register.notice_days', ['days' => $record->notice_days]),
            default => '—',
        };
    }

    /**
     * The report as CSV — see App\Contracts\DeliverableReport.
     *
     * Reads the TABLE's own query, so a filtered export is what the operator is looking at rather
     * than the whole register under a filename that says otherwise.
     */
    public function reportCsv(): array
    {
        $headers = [
            __('admin.fields.clause_type'),
            __('admin.tables.invoice.tenant'),
            __('admin.tables.invoice.unit'),
            __('admin.tables.lease.reference'),
            __('admin.fields.clause_summary'),
            __('admin.fields.clause_trigger'),
            __('admin.fields.clause_applies_from'),
            __('admin.fields.clause_applies_to'),
            __('admin.fields.clause_source_reference'),
        ];

        $rows = $this->getFilteredTableQuery()
            ->with(['lease.tenant', 'lease.unit'])
            ->get()
            ->map(fn (LeaseClause $c): array => [
                $c->label(),
                $c->lease?->tenant?->name,
                $c->lease?->unit?->code,
                $c->lease?->reference,
                $c->summary,
                self::trigger($c),
                $c->applies_from?->toDateString(),
                $c->applies_to?->toDateString(),
                $c->source_reference,
            ])
            ->all();

        return [
            'filename' => 'clause-register-'.CarbonImmutable::today()->toDateString(),
            'headers' => $headers,
            'rows' => $rows,
        ];
    }
}
