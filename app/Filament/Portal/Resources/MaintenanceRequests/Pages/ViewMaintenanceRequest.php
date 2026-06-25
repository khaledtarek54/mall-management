<?php

namespace App\Filament\Portal\Resources\MaintenanceRequests\Pages;

use App\Filament\Portal\Resources\MaintenanceRequests\MaintenanceRequestResource;
use App\Models\MaintenanceRequest;
use App\Models\Tenant;
use App\Services\MaintenanceRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;

class ViewMaintenanceRequest extends ViewRecord
{
    protected static string $resource = MaintenanceRequestResource::class;

    public function getTitle(): string
    {
        /** @var MaintenanceRequest $record */
        $record = $this->record;
        return $record->reference;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('addComment')
                ->label(__('admin.maintenance.add_comment'))
                ->icon('heroicon-o-chat-bubble-left-right')
                ->color('primary')
                ->visible(fn () => $this->record->isOpen())
                ->modalHeading(__('admin.maintenance.add_comment'))
                ->schema([
                    Textarea::make('body')
                        ->label(__('admin.maintenance.body'))
                        ->required()
                        ->rows(4)
                        ->columnSpanFull(),
                ])
                ->action(function (array $data) {
                    /** @var Tenant $tenant */
                    $tenant = \App\Support\Portal::tenant();
                    app(MaintenanceRequestService::class)
                        ->comment($this->record, $tenant, $data['body'], false);

                    // If admin asked for tenant input, replying flips back to in_progress.
                    if ($this->record->status === 'awaiting_tenant') {
                        app(MaintenanceRequestService::class)
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
                ->visible(fn () => in_array($this->record->status, ['submitted', 'acknowledged'], true))
                ->requiresConfirmation()
                ->modalHeading(fn () => __('admin.maintenance.cancel_modal_heading', ['ref' => $this->record->reference]))
                ->modalDescription(__('admin.maintenance.cancel_modal_description'))
                ->action(function () {
                    app(MaintenanceRequestService::class)
                        ->transition($this->record, 'cancelled');

                    Notification::make()
                        ->title(__('admin.maintenance.cancelled'))
                        ->warning()
                        ->send();
                }),
        ];
    }
}
