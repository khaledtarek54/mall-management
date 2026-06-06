<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PortalPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    // OwnerPanelProvider is registered conditionally in AppServiceProvider
    // (feature flag OWNER_PORTAL_ENABLED) so the /owner panel can be disabled.
    PortalPanelProvider::class,
];
