<?php

namespace App\Filament\Admin\Resources\Assets\Pages;

use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Filament\Admin\Resources\Concerns\FillsCustomFields;
use Filament\Actions\DeleteAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Facades\Filament;
use Filament\Resources\Pages\EditRecord;

class EditAsset extends EditRecord
{
    use FillsCustomFields;

    protected static string $resource = AssetResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // **ARCHIVING THE MALL YOU ARE STANDING IN CANNOT LAND YOU INSIDE IT.**
            //
            // Filament builds the post-delete redirect from `Filament::getTenant()`, so deleting a
            // property sent the operator to that property's own index — and `IdentifyTenant`
            // refuses a trashed tenant. Measured: delete → `/admin/NG/assets` → **404**.
            //
            // It was reachable before (only if the switcher already sat on the mall being
            // archived); it became the DEFAULT path the moment the register's Edit link started
            // opening a mall in its own segment, which is the whole point of that change. Found by
            // review, not by the suite — and it is the third time in this piece of work that a fix
            // shipped the very cost used to reject an alternative.
            DeleteAction::make()
                ->successRedirectUrl(fn (): ?string => static::stillReachableIndex()),
            ForceDeleteAction::make()
                ->successRedirectUrl(fn (): ?string => static::stillReachableIndex()),
            RestoreAction::make(),
        ];
    }

    /**
     * The Properties list under a mall this operator can still enter.
     *
     * The one being archived is excluded by `getTenants()` itself — it filters soft-deleted — so
     * this needs no exclusion of its own; asking the panel is what keeps the two answers from
     * drifting. Null when nothing is left, which hands the redirect back to Filament: a user with
     * no property at all belongs on the tenant-registration screen, and that is its decision to
     * make, not this page's.
     */
    protected static function stillReachableIndex(): ?string
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $next = Filament::auth()->user()?->getTenants($panel)->first();

        return $next ? AssetResource::getUrl('index', tenant: $next) : null;
    }
}
