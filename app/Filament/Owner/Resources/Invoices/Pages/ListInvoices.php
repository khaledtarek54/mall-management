<?php

namespace App\Filament\Owner\Resources\Invoices\Pages;

use App\Filament\Owner\Resources\Invoices\InvoiceResource;
use Filament\Resources\Pages\ListRecords;

class ListInvoices extends ListRecords
{
    protected static string $resource = InvoiceResource::class;
}
