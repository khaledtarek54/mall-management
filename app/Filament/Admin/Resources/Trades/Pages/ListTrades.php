<?php

namespace App\Filament\Admin\Resources\Trades\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Trades\TradeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListTrades extends ListRecords
{
    protected static string $resource = TradeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            GuideAction::for(static::getResource()),
            CreateAction::make(),
        ];
    }
}
