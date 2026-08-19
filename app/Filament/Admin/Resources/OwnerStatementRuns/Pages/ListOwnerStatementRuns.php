<?php

namespace App\Filament\Admin\Resources\OwnerStatementRuns\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Models\OwnerStatement;
use App\Models\OwnerStatementRun;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Support\Filament\PropertyField;
use App\Support\StatusTabs;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class ListOwnerStatementRuns extends ListRecords
{
    protected static string $resource = OwnerStatementRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            Action::make('generate')
                ->label(__('admin.owner_statements.actions.generate'))
                ->icon('heroicon-o-plus-circle')
                ->visible(fn () => OwnerStatementRunResource::canGenerate())
                ->authorize(fn () => OwnerStatementRunResource::canGenerate())
                ->schema([
                    PropertyField::make()
                        ->label(__('admin.owner_statements.fields.property')),
                    Select::make('accounting_period_id')
                        ->label(__('admin.owner_statements.fields.period'))
                        ->options(fn () => AccountingPeriod::query()
                            ->orderByDesc('starts_on')
                            ->get()
                            ->mapWithKeys(fn (AccountingPeriod $p) => [$p->id => Carbon::parse($p->starts_on)->format('M Y')])
                            ->all())
                        ->required()
                        ->native(false),
                ])
                ->action(function (array $data): void {
                    // The property is client-supplied — re-validate it against the user's scope.
                    OwnerStatementRunResource::assertAssetInScope($data['asset_id'] ?? null);

                    $asset = Asset::findOrFail($data['asset_id']);
                    $period = AccountingPeriod::findOrFail($data['accounting_period_id']);

                    try {
                        app(GenerateOwnerStatementRunService::class)->generate($asset, $period);
                    } catch (\DomainException $e) {
                        Notification::make()->title($e->getMessage())->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('admin.owner_statements.notices.generated'))->success()->send();
                }),
        ];
    }

    /**
     * The month's worklist: what still has to be finalised, and what still has to go OUT.
     *
     * **`sent` is a STATEMENT status, not a run status.** A run is only ever draft / finalised /
     * superseded — Send marks the child statement and leaves the run finalised. Both tabs filtered
     * on `owner_statement_runs.status` until 2026-08-11, so Sent could never match a row and
     * Finalised mixed the already-sent with the still-owed: the two questions an operator opens
     * this list to ask were the two it could not answer. They query the child now.
     */
    public function getTabs(): array
    {
        $sent = fn (Builder $query) => $query
            ->where('status', OwnerStatementRun::STATUS_FINALISED)
            ->whereHas('statements', fn (Builder $q) => $q->where('status', OwnerStatement::STATUS_SENT));

        return StatusTabs::build(OwnerStatementRunResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'draft' => ['label' => __('admin.owner_statements.statuses.draft'), 'statuses' => ['draft'], 'badge' => true, 'color' => 'warning'],
            // Finalised = posted but NOT yet with the owner. Badged, because that is work waiting.
            'finalised' => [
                'label' => __('admin.owner_statements.statuses.finalised'),
                'query' => fn (Builder $query) => $query
                    ->where('status', OwnerStatementRun::STATUS_FINALISED)
                    ->whereDoesntHave('statements', fn (Builder $q) => $q->where('status', OwnerStatement::STATUS_SENT)),
                'badge' => true,
                'color' => 'info',
            ],
            'sent' => ['label' => __('admin.owner_statements.statuses.sent'), 'query' => $sent],
            'superseded' => ['label' => __('admin.owner_statements.statuses.superseded'), 'statuses' => ['superseded']],
        ]);
    }
}
