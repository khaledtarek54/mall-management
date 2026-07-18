<?php

namespace App\Filament\Admin\Resources\OwnerStatementRuns\Pages;

use App\Filament\Admin\Resources\OwnerStatementRuns\OwnerStatementRunResource;
use App\Models\AccountingPeriod;
use App\Models\Asset;
use App\Services\OwnerAccounting\GenerateOwnerStatementRunService;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Carbon;

class ListOwnerStatementRuns extends ListRecords
{
    protected static string $resource = OwnerStatementRunResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('generate')
                ->label(__('admin.owner_statements.actions.generate'))
                ->icon('heroicon-o-plus-circle')
                ->visible(fn () => OwnerStatementRunResource::canGenerate())
                ->authorize(fn () => OwnerStatementRunResource::canGenerate())
                ->schema([
                    Select::make('asset_id')
                        ->label(__('admin.owner_statements.fields.property'))
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->default(fn () => TenantScope::currentAssetId())
                        ->disabled(fn () => TenantScope::currentAssetId() !== null)
                        ->dehydrated()
                        ->required()
                        ->native(false),
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
}
