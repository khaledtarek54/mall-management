<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\Pages;

use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMarketingBudget extends EditRecord
{
    protected static string $resource = MarketingBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
