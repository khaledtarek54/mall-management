<?php

namespace App\Filament\Admin\Resources\Assets\Pages;

use App\Filament\Admin\Resources\Assets\AssetResource;
use App\Support\Filament\PropertyLink;
use Filament\Resources\Pages\CreateRecord;

class CreateAsset extends CreateRecord
{
    protected static string $resource = AssetResource::class;

    /**
     * Whoever adds a property can work in it — see `Asset::assignTo()`. Without this a `manager`
     * created a mall that was then invisible and unreachable to them: not assigned, absent from the
     * switcher, 404 from every URL. It is what makes the redirect below land somewhere.
     */
    protected function afterCreate(): void
    {
        if ($user = auth()->user()) {
            $this->getRecord()->assignTo($user);
        }
    }

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
     * mall by creating it — until `afterCreate()` above assigns them, which is what makes this
     * redirect reach a page rather than a 404. Null still falls back to Filament's own answer,
     * because an assignment can be refused by a future rule and a link must not assume one.
     */
    protected function getRedirectUrl(): string
    {
        return PropertyLink::to(AssetResource::class, $this->getRecord())
            ?? parent::getRedirectUrl();
    }
}
