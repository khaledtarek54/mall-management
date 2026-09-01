<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\KeepsFilterAnswered;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\AccountingPeriods\AccountingPeriodResource;
use App\Filament\Admin\Resources\Payments\PaymentResource;
use App\Filament\Admin\Resources\TenantSalesDeclarations\TenantSalesDeclarationResource;
use App\Filament\Admin\Resources\VendorBills\VendorBillResource;
use App\Services\Accounting\MonthEndReadinessService;
use App\Support\Modules;
use App\Support\TenantScope;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Pages\Page;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Month-end close — the checklist that knows its own state (UX-04).
 *
 * Closing a month here was tribal knowledge: bill everything, chase the declarations, get the
 * receipts in, post the AP, sync the ledger, check the books tie out, close. Every one of those
 * facts was already computable and none of them was on a screen, so "have we done it all" was
 * answered from memory, once a month, by whoever remembered. This is Yardi's Month-End dashboard.
 *
 * The page is deliberately **read-only about status and a link about everything else**: each row
 * shows a state, a count and the route to the thing that clears it. `MonthEndReadinessService`
 * derives every number from the service that already owns that decision, and closing itself stays
 * where it already lives (the Accounting Periods resource) rather than being reimplemented here —
 * one place to close a period, one gate to pass.
 */
class MonthEndClose extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use KeepsFilterAnswered;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckCircle;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'month-end-close';

    /** The month being closed, `Y-m`. */
    public ?string $period = null;

    private ?array $readiness = null;

    public static function canAccess(): bool
    {
        return Modules::enabled('reports') && (Auth::user()?->can('reports.view') ?? false);
    }

    public function mount(): void
    {
        // Defaults to LAST month: you close a month once it is over, not while it is running.
        $this->period = BillingRunPreview::parsePeriod(
            request()->query('period', CarbonImmutable::now()->subMonthNoOverflow()->format('Y-m')),
        )->format('Y-m');
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 3])
                ->schema([
                    Select::make('period')
                        ->label(__('admin.month_end.period'))
                        ->options(fn (): array => BillingRunPreview::periodOptions())
                        ->native(false)
                        // NOT CLEARABLE. Filament renders a blank option on every Select unless it is
                        // told otherwise, and clearing one sets the bound Livewire property to null —
                        // which UNSETS a non-nullable typed property, so every later read of it throws
                        // and the page 500s. Measured on all seven report screens that had it.
                        //
                        // The fix is the control, not the type: there is no such thing as "no fiscal
                        // year" or "no period" for a statement, so offering the blank was offering an
                        // action that cannot work. Where a blank IS an answer it stays — `period` on
                        // the shared ledger bar means "full year", says so in its placeholder, and is
                        // typed `?string` accordingly.
                        ->selectablePlaceholder(false)
                        ->live()
                        ->afterStateUpdated(fn () => $this->readiness = null),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.month_end.title');
    }

    public function getSubheading(): ?string
    {
        $r = $this->readiness();

        if ($r['closed']) {
            return __('admin.month_end.subheading_closed', ['period' => $r['period_label']]);
        }

        if ($r['ready']) {
            return __('admin.month_end.subheading_ready', ['period' => $r['period_label']]);
        }

        return __('admin.month_end.subheading_open', [
            'period' => $r['period_label'],
            'blocking' => $r['blocking'],
            'outstanding' => $r['outstanding'],
        ]);
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.month_end.nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            // Closing lives in the Accounting Periods resource and stays there — one place to
            // close a period, one gate. This is the route to it, carrying the month.
            Action::make('close')
                ->label(__('admin.month_end.go_to_close'))
                ->icon('heroicon-o-lock-closed')
                ->color(fn (): string => $this->readiness()['ready'] ? 'primary' : 'gray')
                ->url(fn (): string => AccountingPeriodResource::getUrl())
                ->visible(fn (): bool => ! $this->readiness()['closed']
                    && (Auth::user()?->can('accounting_periods.manage') ?? false)),
        ];
    }

    /** @return array<string, mixed> */
    protected function readiness(): array
    {
        return $this->readiness ??= app(MonthEndReadinessService::class)->for(
            BillingRunPreview::parsePeriod($this->period),
            TenantScope::currentAssetId(),
        );
    }

    /** Where an operator goes to clear a given step. */
    protected function stepUrl(string $key): ?string
    {
        return match ($key) {
            'billing_posted' => BillingRunPreview::getUrl(['period' => $this->period]),
            'sales_declared' => TenantSalesDeclarationResource::getUrl(),
            'payments_settled' => PaymentResource::getUrl(),
            'vendor_bills_posted' => VendorBillResource::getUrl(),
            'ledger_in_sync', 'period_closed' => AccountingPeriodResource::getUrl(),
            'books_tie_out' => Reports::getUrl(),
            default => null,
        };
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): Collection => new Collection($this->readiness()['steps']))
            ->paginated(false)
            ->columns([
                TextColumn::make('step')
                    ->label(__('admin.month_end.step'))
                    ->state(fn (array $record): string => __("admin.month_end.steps.{$record['key']}"))
                    ->weight('medium')
                    // The WHY, not just the what — a checklist row nobody understands gets ticked
                    // without being done.
                    ->description(fn (array $record): string => __("admin.month_end.why.{$record['key']}")),
                TextColumn::make('status')
                    ->label(__('admin.month_end.status'))
                    ->badge()
                    ->state(fn (array $record): string => __("admin.month_end.status_labels.{$record['status']}"))
                    ->color(fn (array $record): string => match ($record['status']) {
                        MonthEndReadinessService::OK, MonthEndReadinessService::DONE => 'success',
                        MonthEndReadinessService::BLOCKED => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('count')
                    ->label(__('admin.month_end.outstanding'))
                    ->alignEnd()
                    ->state(fn (array $record): string => $record['count'] > 0 ? (string) $record['count'] : '—')
                    // The service's own message when it has one — never a paraphrase of it.
                    ->tooltip(fn (array $record): ?string => $record['detail']),
            ])
            ->recordUrl(fn (array $record): ?string => $this->stepUrl($record['key']))
            ->emptyStateHeading(__('admin.month_end.empty'));
    }

    /**
     * `$period` is never blank — the Select offers no clear, and a payload that sends one is
     * restored here rather than left to break the page. {@see KeepsFilterAnswered}
     *
     * @return array<string, mixed>
     */
    protected function answerableFilters(): array
    {
        return ['period' => now()->format('Y-m')];
    }
}
