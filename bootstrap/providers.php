<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\Filament\PortalPanelProvider;
use App\Providers\Filament\VendorPanelProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    PortalPanelProvider::class,
    VendorPanelProvider::class,
];
