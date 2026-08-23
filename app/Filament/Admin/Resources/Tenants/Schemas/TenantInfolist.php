<?php

namespace App\Filament\Admin\Resources\Tenants\Schemas;

use App\Models\Tenant;
use App\Support\Filament\CustomFieldsSchema;
use App\Support\TenantScope;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * Tenant 360 — the customer, not one of their leases (UX-07).
 *
 * **Why this is a customer-level screen.** Yardi's customer-vs-lease split exists precisely so a
 * view like this can exist: a tenant may hold several leases across several malls, and "how exposed
 * are we to this company" is not a question any single lease can answer.
 *
 * **Money first, because that is what the screen is opened for.** The exposure figures lead; the tax
 * identifiers and the address are below them, where an accountant looks them up rather than being
 * made to scroll past them.
 *
 * **Every money figure is property-scoped** through `TenantScope::visibleAssetIds()`. A tenant
 * trading in two malls has ONE row here, so an unscoped total would show a mall-A operator what the
 * company owes in mall B — the cross-property AR leak the tenants table is already careful about.
 */
class TenantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.tables.tenant.exposure'))
                ->description(__('admin.tables.tenant.exposure_hint'))
                ->columns(4)
                ->components([
                    TextEntry::make('outstanding')
                        ->label(__('admin.reports.grand_total'))
                        ->state(fn (Tenant $record): string => 'EGP '.number_format(
                            $record->outstandingBalance(TenantScope::visibleAssetIds()), 2,
                        ))
                        ->weight('bold')
                        ->color(fn (Tenant $record): string => $record->outstandingBalance(TenantScope::visibleAssetIds()) > 0
                            ? 'danger'
                            : 'success'),
                    TextEntry::make('credit')
                        ->label(__('admin.tables.tenant.credit_on_account'))
                        ->state(fn (Tenant $record): string => 'EGP '.number_format(
                            $record->creditBalance(TenantScope::visibleAssetIds()), 2,
                        ))
                        ->color('success'),
                    TextEntry::make('active_leases')
                        ->label(__('admin.tables.tenant.active_leases'))
                        ->state(fn (Tenant $record): int => $record->leases()->where('status', 'active')->count())
                        ->badge(),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => __('admin.statuses.tenant')[$state] ?? $state),
                ]),
            Section::make(__('admin.sections.contact'))
                ->columns(3)
                ->components([
                    TextEntry::make('name')->label(__('admin.fields.name')),
                    TextEntry::make('legal_name')->label(__('admin.fields.legal_name'))->placeholder('—'),
                    TextEntry::make('type')
                        ->label(__('admin.fields.type'))
                        ->badge()
                        // The same two labels the form offers — not a new enum block, which would
                        // be a second place for them to drift.
                        ->formatStateUsing(fn (?string $state): string => match ($state) {
                            'individual' => __('admin.fields.individual'),
                            'company' => __('admin.fields.company'),
                            default => $state ?? '—',
                        }),
                    TextEntry::make('contact_person')->label(__('admin.fields.contact_person'))->placeholder('—'),
                    TextEntry::make('phone')->label(__('admin.fields.phone'))->copyable()->placeholder('—'),
                    TextEntry::make('email')->label(__('admin.fields.email'))->copyable()->placeholder('—'),
                ]),
            Section::make(__('admin.sections.tax_identity'))
                ->columns(3)
                ->collapsed()
                ->components([
                    TextEntry::make('tax_id')->label(__('admin.fields.tax_id'))->copyable()->placeholder('—'),
                    TextEntry::make('national_id')->label(__('admin.fields.national_id'))->placeholder('—'),
                    TextEntry::make('commercial_register')
                        ->label(__('admin.fields.commercial_register'))
                        ->copyable()
                        ->placeholder('—'),
                ]),
            // The operator's own fields (D-7). Nothing renders until one is answered.
            ...CustomFieldsSchema::infolist(),

        ]);
    }
}
