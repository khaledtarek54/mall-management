<?php

namespace App\Filament\Portal\Resources\TenantRequests\Pages;

use App\Filament\Portal\Resources\TenantRequests\TenantRequestResource;
use App\Models\Tenant;
use App\Models\TenantRequest;
use App\Services\TenantRequestService;
use App\Support\Portal;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewTenantRequest extends ViewRecord
{
    protected static string $resource = TenantRequestResource::class;

    public function getTitle(): string
    {
        /** @var TenantRequest $record */
        $record = $this->record;
        return $record->reference;
    }

    /**
     * ⚠️ Every write action here must gate on `Portal::isAdmin()` in BOTH `visible()` and
     * `action()`. `visible()` alone is **not** an authorization gate: Filament's
     * `InteractsWithActions::mountAction()` checks `isDisabled()` and never `isVisible()`,
     * so a hidden action is still dispatchable by anyone who can craft the Livewire call.
     * These two actions shipped with no isAdmin() check at all, so a read-only TenantUser
     * could cancel and comment on their company's requests (fixed 2026-07-16 — proven in
     * tests/Feature/Regression/PortalReadOnlyDispatchGuardTest.php). Mirrors the pattern
     * already used in Portal/.../InvoicesTable.php.
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('addComment')
                ->label(__('admin.maintenance.add_comment'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary')
                ->visible(fn () => Portal::isAdmin() && $this->record->isOpen())
                ->modalHeading(__('admin.maintenance.add_comment'))
                ->schema([
                    Textarea::make('body')
                        ->label(__('admin.maintenance.body'))
                        ->required()
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data) {
                    // The real gate — visible() above only styles the page.
                    abort_unless(Portal::isAdmin() && $this->record->isOpen(), 403);

                    /** @var Tenant $tenant */
                    $tenant = Portal::tenant();
                    app(TenantRequestService::class)
                        ->comment($this->record, $tenant, $data['body'], false);

                    // If admin asked for tenant input, replying flips back to in_progress.
                    if ($this->record->status === 'awaiting_tenant') {
                        app(TenantRequestService::class)
                            ->transition($this->record, 'in_progress');
                    }

                    Notification::make()
                        ->title(__('admin.maintenance.comment_sent'))
                        ->success()
                        ->send();
                }),

            Action::make('cancel')
                ->label(__('admin.maintenance.cancel_request'))
                ->icon('heroicon-o-x-circle')
                ->color('danger')
                ->visible(fn () => Portal::isAdmin() && $this->isCancellable())
                ->requiresConfirmation()
                ->modalHeading(fn () => __('admin.maintenance.cancel_modal_heading', ['ref' => $this->record->reference]))
                ->modalDescription(__('admin.maintenance.cancel_modal_description'))
                ->action(function () {
                    // The real gate — see the note on getHeaderActions().
                    abort_unless(Portal::isAdmin() && $this->isCancellable(), 403);

                    app(TenantRequestService::class)
                        ->transition($this->record, 'cancelled');

                    Notification::make()
                        ->title(__('admin.maintenance.cancelled'))
                        ->warning()
                        ->send();
                }),
        ];
    }

    /**
     * A tenant may withdraw a request only before work has started. Named once so the
     * button's visibility and its authorization guard can never drift apart.
     */
    private function isCancellable(): bool
    {
        return in_array($this->record->status, ['submitted', 'acknowledged'], true);
    }
}
