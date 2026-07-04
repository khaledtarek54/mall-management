<?php

namespace App\Filament\Admin\Resources\FixedAssets\Pages;

use App\Filament\Admin\Resources\FixedAssets\FixedAssetResource;
use App\Services\DepreciationService;
use App\Support\TenantScope;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;

class ListFixedAssets extends ListRecords
{
    protected static string $resource = FixedAssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // Post this month's straight-line charge across all active assets (same
            // work the monthly cron does — idempotent, safe to click twice).
            Action::make('post_depreciation')
                ->label(__('admin.fixed_assets.actions.post_depreciation'))
                ->icon('heroicon-o-calculator')
                ->color('primary')
                ->requiresConfirmation()
                ->visible(fn () => auth()->user()?->can('fixed_assets.edit') ?? false)
                ->authorize(fn () => auth()->user()?->can('fixed_assets.edit') ?? false)
                ->action(function (): void {
                    abort_unless(auth()->user()?->can('fixed_assets.edit') ?? false, 403);
                    // Scope to the properties this user can see — a single-property
                    // accounting user must never post another mall's depreciation.
                    // visibleAssetIds() is null for portfolio users → posts everything.
                    $count = app(DepreciationService::class)->run(now(), TenantScope::visibleAssetIds());
                    Notification::make()
                        ->title(__('admin.fixed_assets.depreciation_posted'))
                        ->body(__('admin.fixed_assets.depreciation_posted_body', ['count' => $count]))
                        ->success()
                        ->send();
                }),
            CreateAction::make()->visible(fn () => FixedAssetResource::canCreate()),
        ];
    }
}
