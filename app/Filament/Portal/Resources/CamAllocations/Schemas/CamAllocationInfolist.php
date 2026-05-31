<?php

namespace App\Filament\Portal\Resources\CamAllocations\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

class CamAllocationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.sections.cam_period'))
                ->columns(3)
                ->components([
                    TextEntry::make('pool.period_year')
                        ->label(__('admin.fields.period_year'))
                        ->badge(),
                    TextEntry::make('pool.asset.name')
                        ->label(__('admin.fields.asset')),
                    TextEntry::make('lease.unit.code')
                        ->label(__('admin.tables.tenant.units'))
                        ->badge(),
                ]),
            Section::make(__('admin.sections.cam_breakdown'))
                ->columns(2)
                ->components([
                    TextEntry::make('pro_rata_share_pct')
                        ->label(__('admin.fields.your_share'))
                        ->suffix(' %')
                        ->numeric(2),
                    TextEntry::make('pool.total_actual_expense')
                        ->label(__('admin.fields.total_actual_expense'))
                        ->money('EGP'),
                    TextEntry::make('allocated_amount')
                        ->label(__('admin.fields.allocated_amount'))
                        ->money('EGP'),
                    TextEntry::make('estimated_paid')
                        ->label(__('admin.fields.estimated_paid'))
                        ->money('EGP'),
                    TextEntry::make('true_up_amount')
                        ->label(__('admin.fields.true_up_amount'))
                        ->money('EGP')
                        ->color(fn ($state): string => (float) $state > 0 ? 'danger' : ((float) $state < 0 ? 'success' : 'gray'))
                        ->helperText(__('admin.helpers.true_up_explainer')),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.statuses.cam_allocation.{$state}")),
                ]),
        ]);
    }
}
