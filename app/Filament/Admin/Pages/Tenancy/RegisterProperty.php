<?php

namespace App\Filament\Admin\Pages\Tenancy;

use App\Filament\Admin\Resources\Assets\Schemas\AssetForm;
use App\Models\Asset;
use Filament\Pages\Tenancy\RegisterTenant;
use Filament\Schemas\Schema;

/**
 * Tenant registration page shown when a user has zero properties assigned.
 * Filament redirects new operators here to create their first property,
 * after which the panel switches into that property's context.
 */
class RegisterProperty extends RegisterTenant
{
    public static function getLabel(): string
    {
        return __('admin.tenancy.register_label');
    }

    public function getHeading(): string
    {
        return __('admin.tenancy.register_heading');
    }

    public function getSubheading(): ?string
    {
        return __('admin.tenancy.register_subheading');
    }

    public function form(Schema $schema): Schema
    {
        return AssetForm::configure($schema);
    }

    protected function handleRegistration(array $data): Asset
    {
        $asset = Asset::create($data);

        if ($user = auth()->user()) {
            $user->assignedAssets()->syncWithoutDetaching([
                $asset->id => [
                    'role' => 'manager',
                    'assigned_at' => now(),
                ],
            ]);
        }

        return $asset;
    }
}
