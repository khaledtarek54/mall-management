<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Models\PurchaseRequest;
use App\Services\FacilityWorkOrderService;
use App\Services\TenantRequestService;
use App\Support\Modules;
use App\Support\Translate;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Concerns\InteractsWithSchemas;
use Filament\Schemas\Contracts\HasSchemas;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;

/**
 * FR-FIN-04 — workflow visualization. A read-only reference of the system's state machines: for
 * each workflow, every status and the statuses it may move to (a terminal status has none).
 *
 * Driven straight off the `TRANSITIONS` matrices that ENFORCE the flows (PurchaseRequest,
 * FacilityWorkOrder, TenantRequest), so this can never document a transition the services don't
 * actually allow — no domain change, just a rendering of the single source of truth.
 */
class Workflows extends Page implements HasSchemas, HasTable
{
    use InteractsWithSchemas;
    use InteractsWithTable;
    use SavesReportViews;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowPathRoundedSquare;

    protected string $view = 'filament.pages.ledger-report';

    protected static string $routePath = 'workflows';

    /**
     * The three state machines this page draws — what each belongs to, who may read it, and the
     * catalogue that already NAMES its statuses.
     *
     * ONE registry, because the gate and the rows were answering the same question in two places and
     * disagreeing about it. Both halves were wrong, and neither is visible from the other:
     *
     *  - **The gate named a module this page does not draw.** It was `Modules::enabled('approvals')`
     *    alone, and `approvals` owns the value-threshold approval LADDER (`approval_rules` — see
     *    `Modules::FEATURE_OF`), which is none of these three. Switching that ladder off therefore
     *    took the tenant-request and work-order maps down with it, and switching PROCUREMENT off
     *    left its purchase-request map on the page, describing a module this install no longer runs.
     *  - **The permission list omitted `facility.view`** — it read
     *    `['requests.view', 'procurement.view']`, on a page whose own class docblock names
     *    `FacilityWorkOrder` as one of the three.
     *
     * `transitions` is the matrix that ENFORCES the flow, so this can never document a hop the
     * services do not allow. `statuses` is the lang group the request board, the work-order list and
     * the purchase-request list already label from — never a fourth vocabulary.
     *
     * @var array<string, array{module: string, permission: string, statuses: string, transitions: array<string, list<string>>}>
     */
    private const WORKFLOWS = [
        'tenant_request' => [
            'module' => 'requests',
            'permission' => 'requests.view',
            'statuses' => 'admin.statuses.tenant_request',
            'transitions' => TenantRequestService::TRANSITIONS,
        ],
        'work_order' => [
            'module' => 'facility',
            'permission' => 'facility.view',
            'statuses' => 'admin.facility.statuses',
            'transitions' => FacilityWorkOrderService::TRANSITIONS,
        ],
        'purchase_request' => [
            'module' => 'procurement',
            'permission' => 'procurement.view',
            'statuses' => 'admin.procurement.statuses',
            'transitions' => PurchaseRequest::TRANSITIONS,
        ],
    ];

    /**
     * The state machines this install actually runs. A module switched off has none here.
     *
     * @return array<string, array{module: string, permission: string, statuses: string, transitions: array<string, list<string>>}>
     */
    private static function enabledWorkflows(): array
    {
        return array_filter(
            self::WORKFLOWS,
            static fn (array $spec): bool => Modules::enabled($spec['module']),
        );
    }

    public static function canAccess(): bool
    {
        // A harmless read-only reference — reachable while at least one state machine is left on the
        // page AND this operator works it. Per workflow, not per page: it maps three modules, so a
        // single module key is wrong for two of them whichever one it names.
        //
        // The permission is a UNION and the ROWS are deliberately not narrowed by it. This page
        // holds no records — only the transition matrices themselves — so a technician reading the
        // procurement ladder learns nothing they may not know, and narrowing the rows would take a
        // reference away from the people it was written for. What the rows ARE narrowed by is the
        // MODULE, because mapping a workflow the operator has switched off describes something this
        // install does not do.
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        foreach (self::enabledWorkflows() as $spec) {
            if ($user->can($spec['permission'])) {
                return true;
            }
        }

        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('admin.workflows.nav');
    }

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::class),
            $this->saveViewAction(),
        ];
    }

    public function getTitle(): string
    {
        return __('admin.workflows.title');
    }

    public function getSubheading(): ?string
    {
        return __('admin.workflows.subheading');
    }

    /** An empty filter strip — the shared report view renders `$this->filtersForm`. */
    public function filtersForm(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    /** @return array<string, array<string, mixed>> keyed "workflow:from" so table rows are stable */
    protected function rows(): array
    {
        $rows = [];

        foreach (self::enabledWorkflows() as $workflow => $spec) {
            foreach ($spec['transitions'] as $from => $tos) {
                $rows["{$workflow}:{$from}"] = [
                    'workflow' => (string) __("admin.workflows.names.{$workflow}"),
                    'state' => self::stateLabel($spec['statuses'], (string) $from),
                    'to' => array_map(fn (string $s) => self::stateLabel($spec['statuses'], $s), $tos),
                    'terminal' => $tos === [],
                ];
            }
        }

        return $rows;
    }

    /**
     * A status in the READER's language, from the catalogue the screens already label it from.
     *
     * Measured 2026-09-03: every cell on this page rendered `ucwords(str_replace('_', ' ', $state))`
     * — English typography of the raw database value — so an operator working the Arabic panel read
     * `Awaiting Tenant`, `In Progress`, `Ordered`. Meanwhile `admin.statuses.tenant_request` (7
     * states), `admin.facility.statuses` (4) and `admin.procurement.statuses` (7) between them
     * already name every state in BOTH languages — checked key by key against the three TRANSITIONS
     * matrices, 18 keys and no misses — and are what the request board, the work-order list and the
     * purchase-request list render. Three catalogues, and this was the one surface not reading them.
     *
     * `Translate::orHumanized()` rather than `__($key, [], $fallback)`: that third argument is the
     * LOCALE, which is how the Roles screen came to render in English on the Arabic panel. The
     * humanised value stays as the LAST resort, so a transition added before its catalogue entry
     * still reads as words instead of as a raw lang key.
     */
    private static function stateLabel(string $group, string $state): string
    {
        return Translate::orHumanized("{$group}.{$state}", $state);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn () => $this->rows())
            ->columns([
                TextColumn::make('workflow')
                    ->label(__('admin.workflows.columns.workflow'))
                    ->badge()
                    ->color('primary'),
                TextColumn::make('state')
                    ->label(__('admin.workflows.columns.state'))
                    ->badge()
                    ->color('gray'),
                TextColumn::make('to')
                    ->label(__('admin.workflows.columns.transitions'))
                    // An array state renders one badge per allowed next status; a terminal row is empty.
                    ->badge()
                    ->color('info')
                    ->placeholder('—'),
                IconColumn::make('terminal')
                    ->label(__('admin.workflows.columns.terminal'))
                    ->boolean(),
            ])
            ->paginated(false);
    }
}
