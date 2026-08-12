<?php

namespace App\Filament\Admin\Resources\Invoices\Pages;

use App\Filament\Actions\GuideAction;
use App\Filament\Admin\Resources\Concerns\SavesTableViews;
use App\Filament\Admin\Resources\Invoices\InvoiceResource;
use App\Filament\Imports\OpeningInvoiceImporter;
use App\Support\Imports;
use App\Support\StatusTabs;
use Filament\Actions\CreateAction;
use Filament\Actions\ImportAction;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    use SavesTableViews;

    protected static string $resource = InvoiceResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...$this->savedViewActions(),
            GuideAction::for(static::getResource()),
            CreateAction::make(),
            // Cut-over only: the receivables already outstanding when Atriom took over. Gated on
            // the import right (FR-USR-02: mall_admin is the role that may load data), and hidden
            // once the books are live — an opening balance is a one-off, and an import action
            // sitting on a working AR ledger is an invitation to double-load it.
            ImportAction::make('importOpeningBalances')
                ->label(__('admin.actions.import_opening_invoices'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('gray')
                ->importer(OpeningInvoiceImporter::class)
                // App\Support\Imports is the one home for "who may import" (FR-USR-02) — a
                // hand-rolled permission check here is exactly the drift it exists to prevent,
                // and ImportIsAdminOnlyTest fails the build for it.
                ->visible(fn () => Imports::allowed())
                ->authorize(fn () => Imports::allowed()),
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
