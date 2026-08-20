<?php

namespace App\Filament\Admin\Resources\MarketingBudgets\Pages;

use App\Filament\Admin\Resources\MarketingBudgets\MarketingBudgetResource;
use App\Support\Filament\RefreshesRecordState;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditMarketingBudget extends EditRecord
{
    use RefreshesRecordState;

    /**
     * The Fund section (accrued / spent / balance) is rendered as `TextEntry->state(...)` closures
     * that resolve from the record at RENDER time, so they need no state paths — re-reading the
     * record in the listener is the whole fix. Every spend is created, edited and deleted in the
     * relation manager below, which is a different Livewire component from this form.
     *
     * @return array<int, string>
     */
    protected function derivedStatePaths(): array
    {
        return [];
    }

    protected static string $resource = MarketingBudgetResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
