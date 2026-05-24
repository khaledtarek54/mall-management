<?php

namespace App\Filament\Imports;

use App\Models\Tenant;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;

class TenantImporter extends Importer
{
    protected static ?string $model = Tenant::class;

    public static function getColumns(): array
    {
        return [
            ImportColumn::make('name')
                ->label(__('admin.tables.tenant.name'))
                ->requiredMapping()
                ->rules(['required', 'max:200']),

            ImportColumn::make('legal_name')
                ->label(__('admin.fields.legal_name'))
                ->rules(['nullable', 'max:200']),

            ImportColumn::make('type')
                ->label(__('admin.fields.type'))
                ->rules(['nullable', 'in:individual,company,foreign']),

            ImportColumn::make('tax_id')
                ->label(__('admin.fields.tax_id'))
                ->rules(['nullable', 'max:50']),

            ImportColumn::make('email')
                ->label(__('admin.tables.tenant.email'))
                ->rules(['nullable', 'email', 'max:255']),

            ImportColumn::make('phone')
                ->label(__('admin.tables.tenant.phone'))
                ->rules(['nullable', 'max:50']),

            ImportColumn::make('contact_person')
                ->label(__('admin.fields.contact_person'))
                ->rules(['nullable', 'max:200']),

            ImportColumn::make('address')
                ->label(__('admin.fields.address'))
                ->rules(['nullable', 'max:500']),

            ImportColumn::make('status')
                ->label(__('admin.tables.common.status'))
                ->rules(['nullable', 'in:active,inactive,blacklisted']),
        ];
    }

    public function resolveRecord(): ?Tenant
    {
        // Match on email if present (idempotent re-imports), otherwise create new.
        $email = $this->data['email'] ?? null;

        if ($email) {
            return Tenant::firstOrNew(['email' => $email]);
        }

        return new Tenant;
    }

    public static function getCompletedNotificationBody(Import $import): string
    {
        $body = 'Your tenant import has completed and ' . number_format($import->successful_rows) . ' ' . str('row')->plural($import->successful_rows) . ' imported.';

        if ($failedRowsCount = $import->getFailedRowsCount()) {
            $body .= ' ' . number_format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to import.';
        }

        return $body;
    }

    public function getJobConnection(): ?string
    {
        return 'sync';
    }
}
