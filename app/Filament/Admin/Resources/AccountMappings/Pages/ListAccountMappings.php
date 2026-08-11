<?php

namespace App\Filament\Admin\Resources\AccountMappings\Pages;

use App\Filament\Admin\Resources\AccountMappings\AccountMappingResource;
use App\Support\PostingRoles;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListAccountMappings extends ListRecords
{
    protected static string $resource = AccountMappingResource::class;

    protected function getHeaderActions(): array
    {
        return [
                        \App\Filament\Actions\GuideAction::for(AccountMappingResource::class),
CreateAction::make(),
        ];
    }

    /**
     * Split the way an accountant reads a trial balance. The group is not a column — it comes from
     * `PostingRoles` — so these are query tabs rather than status tabs on a column.
     */
    public function getTabs(): array
    {
        $byGroup = fn (string $group) => [
            'label' => PostingRoles::groupLabel($group),
            'query' => fn ($query) => $query->whereIn('key', PostingRoles::keysIn($group)),
        ];

        return StatusTabs::build(AccountMappingResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'asset' => $byGroup(PostingRoles::GROUP_ASSET),
            'liability' => $byGroup(PostingRoles::GROUP_LIABILITY),
            'equity' => $byGroup(PostingRoles::GROUP_EQUITY),
            'revenue' => $byGroup(PostingRoles::GROUP_REVENUE),
            'expense' => $byGroup(PostingRoles::GROUP_EXPENSE),
        ]);
    }
}
