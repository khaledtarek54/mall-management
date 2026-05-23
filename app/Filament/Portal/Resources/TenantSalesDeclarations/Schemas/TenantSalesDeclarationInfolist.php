<?php

namespace App\Filament\Portal\Resources\TenantSalesDeclarations\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantSalesDeclarationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.sections.tenant_sales'))
                ->columns(2)
                ->components([
                    TextEntry::make('lease.reference')
                        ->label(__('admin.resources.lease.singular')),
                    TextEntry::make('lease.unit.code')
                        ->label(__('admin.tables.tenant_sales.unit'))
                        ->badge(),
                    TextEntry::make('period_start')
                        ->label(__('admin.tables.tenant_sales.period'))
                        ->formatStateUsing(fn ($state) => $state->isoFormat('MMM YYYY')),
                    TextEntry::make('declared_sales')
                        ->label(__('admin.tables.tenant_sales.declared_sales'))
                        ->money('EGP'),
                    TextEntry::make('calculated_percentage_rent')
                        ->label(__('admin.tables.tenant_sales.percentage_rent'))
                        ->money('EGP')
                        ->color(fn ($state) => $state > 0 ? 'success' : 'gray'),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_sales.{$state}"))
                        ->color(fn (string $state): string => match ($state) {
                            'submitted' => 'warning',
                            'locked' => 'success',
                            'disputed' => 'danger',
                            default => 'gray',
                        }),
                    TextEntry::make('declared_at')
                        ->label(__('admin.tables.tenant_sales.declared_at'))
                        ->dateTime('d/m/Y H:i'),
                    TextEntry::make('locked_at')
                        ->label(__('admin.tables.tenant_sales.locked_at'))
                        ->dateTime('d/m/Y H:i')
                        ->placeholder('—'),
                ]),
        ]);
    }
}
