<?php

namespace App\Providers;

use Filament\Support\Facades\FilamentView;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        FilamentView::registerRenderHook(
            PanelsRenderHook::TOPBAR_END,
            fn (): string => filament()->getCurrentPanel()?->getId() === 'admin'
                ? Blade::render('@include("filament.operator-switch") @include("filament.language-switch")')
                : Blade::render('@include("filament.language-switch")'),
        );
        FilamentView::registerRenderHook(
            PanelsRenderHook::AUTH_LOGIN_FORM_BEFORE,
            fn (): string => Blade::render('<div class="flex justify-center mb-4">@include("filament.language-switch")</div>'),
        );
    }
}
