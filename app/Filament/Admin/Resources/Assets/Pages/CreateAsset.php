<?php

namespace App\Filament\Admin\Resources\Assets\Pages;

use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Support\Filament\PropertyLink;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    /**
     * **A MALL YOU HAVE JUST CREATED IS THE MALL YOU ARE NOW IN.**
     *
     * `CreateRecord` builds this from `Filament::getTenant()`, so creating Nile Gate while Val
     * Plaza was selected landed on `/admin/VP/assets/{Nile Gate}/edit` — the URL and the switcher
     * naming one mall and the record another, which is the defect the register's Edit link exists
     * to end, at the one moment `AssetResource`'s own docblock singles out: *"a new mall is never
     * the active tenant"*.
     *
     * `PropertyLink::to()` is the same seam that link uses, so the two cannot drift, and it
     * refuses a mall this operator cannot enter — which is the real case here, not a defensive
     * one: `assets.create` is held by `manager` and `mall_admin`, and neither is assigned to a
     * mall by creating it. Null falls back to Filament's own answer rather than sending them to a
     * 404. **That they cannot then open what they just made is a separate, pre-existing hole**
     * (`RegisterProperty::handleRegistration()` attaches the creator; this page does not) and is
     * reported rather than widened into here.
     */
    protected function getRedirectUrl(): string
    {
        return PropertyLink::to(AssetResource::class, $this->getRecord())
            ?? parent::getRedirectUrl();
    }
}
