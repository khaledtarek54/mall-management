<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }

    /**
     * Collections worklist. "Outstanding" is the one an AR clerk lives in — every
     * invoice with money still on it, whether or not it has tipped past its due
     * date — and Overdue is the subset to chase today.
     */
    public function getTabs(): array
    {
        return StatusTabs::build(InvoiceResource::class, [
            'all' => ['label' => __('admin.tabs.all')],
            'draft' => ['label' => __('admin.tabs.draft'), 'statuses' => ['draft'], 'badge' => true, 'color' => 'gray'],
            'outstanding' => ['label' => __('admin.tabs.outstanding'), 'statuses' => ['issued', 'partially_paid', 'overdue'], 'badge' => true, 'color' => 'warning'],
            'overdue' => ['label' => __('admin.tabs.overdue'), 'statuses' => ['overdue'], 'badge' => true, 'color' => 'danger'],
            'disputed' => ['label' => __('admin.tabs.disputed'), 'statuses' => ['disputed'], 'badge' => true, 'color' => 'danger'],
            'paid' => ['label' => __('admin.tabs.paid'), 'statuses' => ['paid']],
        ]);
    }
}
