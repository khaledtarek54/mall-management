<?php

namespace App\Filament\Admin\Resources\FacilityWorkOrders\Pages;

use App\Filament\Admin\Resources\FacilityWorkOrders\FacilityWorkOrderResource;
use App\Services\AcceptWorkOrderService;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class EditFacilityWorkOrder extends EditRecord
{
    // `AcceptWorkOrderService` re-reads the job as a LOCKING read into a new instance — as every
    // service here must — so Filament's own `refreshFormData()` would refill from this page's stale
    // in-memory copy and the form would go on showing "not accepted" after a successful accept. The
    // exact shape CLAUDE.md records for nineteen call sites across eight money pages.
    use RefreshesRecordState;

    /** @return array<int, string> */
    protected function derivedStatePaths(): array
    {
        return ['acknowledged_at'];
    }

    protected static string $resource = FacilityWorkOrderResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // **Accept on the contractor's behalf** — kept, not replaced, and §9 of
            // docs/modules/12b-VENDOR-PORTAL-DESIGN.md is why. The thing that would make the vendor
            // portal a bad idea is contractors who will not log in: `acknowledged_at` would stop
            // being filled by staff and start being filled by nobody, making the response SLA WORSE
            // than before the portal existed. So the admin path stays, and both sides call the same
            // service — two ways to accept a job must not mean two code paths.
            Action::make('accept')
                ->label(__('admin.facility.accept_for_contractor'))
                ->icon('heroicon-o-check-circle')
                ->color('success')
                ->requiresConfirmation()
                ->modalDescription(__('admin.facility.accept_for_contractor_confirm'))
                ->visible(fn () => $this->getRecord()->acknowledged_at === null
                    && ! $this->getRecord()->isTerminal()
                    && FacilityWorkOrderResource::canEdit($this->getRecord()))
                ->authorize(fn () => FacilityWorkOrderResource::canEdit($this->getRecord()))
                ->action(function (): void {
                    abort_unless(FacilityWorkOrderResource::canEdit($this->getRecord()), 403);

                    try {
                        app(AcceptWorkOrderService::class)->accept($this->getRecord(), Auth::user());
                    } catch (ValidationException $e) {
                        Notification::make()->danger()->title($e->getMessage())->send();

                        return;
                    }

                    $this->refreshFormData(['acknowledged_at']);
                    Notification::make()->success()->title(__('admin.facility.accepted'))->send();
                }),
            DeleteAction::make()->visible(fn () => FacilityWorkOrderResource::canDelete($this->getRecord())),
        ];
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Terminal (done/cancelled) work orders are immutable.
        abort_unless(! $this->getRecord()->isTerminal(), 403);
        FacilityWorkOrderResource::assertAssetInScope($data['asset_id'] ?? $this->getRecord()->asset_id);

        return $data;
    }
}
