<?php

namespace App\Providers;

use App\Models\Lease;
use App\Observers\LeaseObserver;
use App\Services\Paymob\PaymobClient;
use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // PaymobClient needs config-driven primitive args; teach the container
        // to build it through the fromConfig factory so controllers + actions
        // can typehint it directly.
        $this->app->singleton(PaymobClient::class, fn () => PaymobClient::fromConfig());
    }

    public function boot(): void
    {
        Lease::observe(LeaseObserver::class);

        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): string => Blade::render('@include("filament.language-switch")'),
        );
        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
            fn (): string => Blade::render('<div class="flex justify-center mb-4">@include("filament.language-switch")</div>'),
        );
    }
}
