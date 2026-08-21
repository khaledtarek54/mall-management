<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Services\MonthlyBillingService;
use App\Support\BillingWindow;
use App\Support\Modules;
use App\Support\OpsLog;
use App\Support\TenantScope;
use BackedEnum;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
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
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;

/**
 * Billing run preview — the dry run an operator reviews BEFORE the month's invoices become real.
 *
 * Posting a month is the single largest money event in the system: one click bills every active
 * lease in the mall. Until this page existed the only control was a confirmation modal, which asks
 * "are you sure" without ever showing what you are being sure about — so a mis-keyed escalation, a
 * charge that silently ended last month, or a lease that quietly fell out of the billable set all
 * became four hundred wrong invoices before anyone could see them. This is Yardi's batch-review
 * step (docs/benchmarks/yardi/08-yardi-ui-ux.md, UX-05).
 *
 * Every row is computed by `MonthlyBillingService::planInvoiceForLease()` — the SAME method the
 * real run persists verbatim. A preview built from a second implementation is a preview that can
 * lie, and the one property this page must have is that what it shows is what will post.
 *
 * Scoped to the current property, and posting from here is scoped the same way: posting charges is
 * a per-property act everywhere else in this system, and the operator is looking at one mall.
 */
class BillingRunPreview extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 2;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'billing-run-preview';

    /** The month being previewed, `Y-m`. */
    public string $period;

    /** Memoised so switching a filter or paginating doesn't recompute the whole mall's plan. */
    private ?array $preview = null;

    public static function canAccess(): bool
    {
        // Viewing the preview is a read of what WOULD bill, so it rides on the invoice read
        // permission; POSTING it is separately gated on invoices.create in the action below.
        // No `Modules::enabled('billing')` here. There is no `billing` key and there must not be
        // one: `Modules::enabled()` returns TRUE for anything outside `Modules::KEYS`, so that call
        // was a guard that could never refuse — it read as a toggle and gated nothing. Billing is
        // what this system IS; a mall that cannot invoice is not running Atriom.
        return Auth::user()?->can('invoices.view') ?? false;
    }

    public function mount(): void
    {
        $this->period = self::parsePeriod(request()->query('period'))->format('Y-m');
    }

    /**
     * Parse a client-supplied `Y-m`, falling back to the current month.
     *
     * `!Y-m`, not `Y-m`: with no day in the format Carbon fills it from TODAY, so on the 29th–31st
     * a shorter month overflows and the period silently shifts forward — the same trap the billing
     * CLI command documents. The `!` resets every unspecified field.
     */
    public static function parsePeriod(mixed $value): CarbonImmutable
    {
        try {
            return CarbonImmutable::createFromFormat('!Y-m', (string) $value)->startOfMonth();
        } catch (\Throwable) {
            return CarbonImmutable::now()->startOfMonth();
        }
    }

    /**
     * The last 12 months plus the next one — the realistic window for a manual run.
     *
     * The rule moved to `App\Support\BillingWindow` when the lease's Generate Invoice action turned
     * out to accept ANY month: this screen refused to preview a period that screen would happily
     * bill. Kept as a method here because the filter and the tests both name it.
     */
    public static function periodOptions(): array
    {
        return BillingWindow::options();
    }

    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()
                ->columns(['sm' => 2, 'lg' => 3])
                ->schema([
                    Select::make('period')
                        ->label(__('admin.billing_preview.period'))
                        ->options(fn (): array => self::periodOptions())
                        ->native(false)
                        ->live()
                        // Recomputing is the whole point of changing the month.
                        ->afterStateUpdated(fn () => $this->preview = null),
                ]),
        ]);
    }

    public function getTitle(): string
    {
        return __('admin.billing_preview.title');
    }

    /** The headline an operator decides on: how many, how much, and how many are being skipped. */
    public function getSubheading(): ?string
    {
        $t = $this->preview()['totals'];

        return __('admin.billing_preview.subheading', [
            'will_bill' => $t['will_bill'],
            'total' => 'EGP '.number_format($t['total'], 2),
            'skipped' => $t['skipped'],
        ]);
    }

    public static function getNavigationGroup(): ?string
    {
        return __('admin.groups.receivables');
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.billing_preview.nav_label');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
            Action::make('post')
                ->label(__('admin.billing_preview.post'))
                ->icon('heroicon-o-play')
                ->color('primary')
                // Nothing to post = nothing to offer. An enabled button that does nothing is how
                // an operator learns not to trust the screen.
                ->visible(fn (): bool => InvoiceResource::canCreate() && $this->preview()['totals']['will_bill'] > 0)
                ->requiresConfirmation()
                ->modalHeading(__('admin.billing_preview.post_modal_heading'))
                ->modalDescription(fn (): string => __('admin.billing_preview.post_modal_description', [
                    'count' => $this->preview()['totals']['will_bill'],
                    'total' => 'EGP '.number_format($this->preview()['totals']['total'], 2),
                    'period' => self::parsePeriod($this->period)->locale(app()->getLocale())->isoFormat('MMMM YYYY'),
                ]))
                ->action(function () {
                    // action() is the real gate. visible() is the UI; a user holding invoices.view
                    // but not invoices.create must not be able to post a property-wide run.
                    abort_unless(InvoiceResource::canCreate(), 403);

                    $assetId = TenantScope::currentAssetId();
                    $stats = app(MonthlyBillingService::class)
                        ->runForPeriod(self::parsePeriod($this->period), $assetId);

                    OpsLog::info('Monthly billing posted from the preview screen', [
                        'period' => $stats['period'],
                        'asset_id' => $assetId,
                        'created' => $stats['created'],
                        'user_id' => Auth::id(),
                    ]);

                    // Re-preview so the table immediately reflects reality (every posted lease now
                    // reads `already_billed`) instead of still offering invoices that now exist.
                    $this->preview = null;

                    $notification = Notification::make()
                        ->title(__('admin.billing_preview.posted_title', ['count' => $stats['created']]))
                        ->body(__('admin.billing_preview.posted_body', [
                            'created' => $stats['created'],
                            'skipped' => $stats['skipped'],
                            'failed' => $stats['failed'],
                        ]));

                    $stats['failed'] > 0 ? $notification->warning() : $notification->success();
                    $notification->send();
                }),
        ];
    }

    /** @return array{period:string, rows:array<int,array<string,mixed>>, totals:array<string,mixed>} */
    protected function preview(): array
    {
        return $this->preview ??= app(MonthlyBillingService::class)->previewForPeriod(
            self::parsePeriod($this->period),
            TenantScope::currentAssetId(),
        );
    }

    public function table(Table $table): Table
    {
        return $table
            // records(), not query(): a row is a computed PLAN, not a stored row — the invoices
            // do not exist yet. Filament hands the closure page + perPage and expects it to slice.
            ->records(function (int $page, int|string $recordsPerPage) {
                $rows = new Collection($this->preview()['rows']);

                if ($recordsPerPage === 'all') {
                    return $rows;
                }

                return new LengthAwarePaginator(
                    $rows->forPage($page, (int) $recordsPerPage),
                    $rows->count(),
                    (int) $recordsPerPage,
                    $page,
                );
            })
            ->columns([
                TextColumn::make('unit_code')
                    ->label(__('admin.tables.invoice.unit'))
                    ->badge()
                    ->color('gray')
                    ->placeholder('—'),
                TextColumn::make('tenant_name')
                    ->label(__('admin.tables.invoice.tenant'))
                    ->weight('medium')
                    ->placeholder('—'),
                TextColumn::make('lease_reference')
                    ->label(__('admin.tables.lease.reference'))
                    ->fontFamily('mono')
                    ->size('xs'),
                TextColumn::make('outcome')
                    ->label(__('admin.billing_preview.outcome'))
                    ->badge()
                    ->state(fn (array $record): string => $record['billable']
                        ? ($record['prorated']
                            ? __('admin.billing_preview.outcome_prorated', ['pct' => round($record['factor'] * 100)])
                            : __('admin.billing_preview.outcome_will_bill'))
                        // The REASON, never a bare "skipped" — "in fit-out" is an answer, "skipped"
                        // is a question. These are the service's own reason codes.
                        : __('admin.billing_preview.reason.'.($record['reason'] ?? 'unknown')))
                    ->color(fn (array $record): string => $record['billable']
                        ? ($record['prorated'] ? 'warning' : 'success')
                        : 'gray'),
                TextColumn::make('line_count')
                    ->label(__('admin.billing_preview.lines'))
                    ->alignEnd()
                    // The lines themselves, so a wrong amount is visible without a drill-down.
                    ->tooltip(fn (array $record): ?string => $record['items']
                        ? collect($record['items'])
                            ->map(fn (array $i): string => $i['description'].': '.number_format((float) $i['total'], 2))
                            ->implode(' · ')
                        : null)
                    ->placeholder('—'),
                TextColumn::make('subtotal')
                    ->label(__('admin.tables.invoice.subtotal'))
                    ->money('EGP')
                    ->alignEnd(),
                TextColumn::make('vat_amount')
                    ->label(__('admin.tables.invoice.vat'))
                    ->money('EGP')
                    ->alignEnd(),
                TextColumn::make('total')
                    ->label(__('admin.tables.invoice.total'))
                    ->money('EGP')
                    ->weight('bold')
                    ->alignEnd(),
                TextColumn::make('due_date')
                    ->label(__('admin.tables.invoice.due_date'))
                    ->date('d/m/Y')
                    ->placeholder('—'),
            ])
            ->defaultPaginationPageOption(25)
            ->emptyStateHeading(__('admin.billing_preview.empty'));
    }
}
