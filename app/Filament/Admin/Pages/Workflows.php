<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Pages\Concerns\SavesReportViews;
use App\Models\PurchaseRequest;
use App\Services\FacilityWorkOrderService;
use App\Services\TenantRequestService;
use App\Support\Modules;
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

    public static function canAccess(): bool
    {
        // A harmless read-only reference — visible to anyone who works one of the workflows it maps,
        // and only while the approval ladder is switched on: with `modules.approvals` off there is
        // no ladder for this page to map.
        return Modules::enabled('approvals')
            && (Auth::user()?->canAny(['requests.view', 'procurement.view']) ?? false);
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

    /** @return array<string, array<string, list<string>>> workflow => (from => to-states) */
    protected function workflows(): array
    {
        return [
            'tenant_request' => TenantRequestService::TRANSITIONS,
            'work_order' => FacilityWorkOrderService::TRANSITIONS,
            'purchase_request' => PurchaseRequest::TRANSITIONS,
        ];
    }

    private function humanize(string $state): string
    {
        return ucwords(str_replace('_', ' ', $state));
    }

    /** @return array<string, array<string, mixed>> keyed "workflow:from" so table rows are stable */
    protected function rows(): array
    {
        $rows = [];
        foreach ($this->workflows() as $workflow => $transitions) {
            foreach ($transitions as $from => $tos) {
                $rows["{$workflow}:{$from}"] = [
                    'workflow' => (string) __("admin.workflows.names.{$workflow}"),
                    'state' => $this->humanize((string) $from),
                    'to' => array_map(fn (string $s) => $this->humanize($s), $tos),
                    'terminal' => $tos === [],
                ];
            }
        }

        return $rows;
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
