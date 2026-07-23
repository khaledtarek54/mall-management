<?php

namespace App\Filament\Portal\Resources\Leases\Schemas;

use App\Models\Lease;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * The tenant's own lease terms, read-only — what they're paying, for what, until when. Native
 * infolist (no hand-rolled Blade), the money terms grouped so a tenant can actually read them.
 */
class LeaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make(__('admin.portal.lease.terms'))
                ->columns(3)
                ->components([
                    TextEntry::make('reference')->label(__('admin.fields.reference')),
                    TextEntry::make('unit.code')->label(__('admin.resources.unit.singular'))->badge(),
                    TextEntry::make('unit.asset.name')->label(__('admin.fields.property')),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.statuses.lease.{$state}"))
                        ->color(fn (string $state) => $state === 'active' ? 'success' : 'gray'),
                    TextEntry::make('commencement_date')->label(__('admin.fields.commencement_date'))->date('d/m/Y')->placeholder('—'),
                    TextEntry::make('expiry_date')->label(__('admin.fields.expiry_date'))->date('d/m/Y')->placeholder('—'),
                ]),

            Section::make(__('admin.portal.lease.charges'))
                ->columns(3)
                ->components([
                    TextEntry::make('base_rent_monthly')->label(__('admin.fields.base_rent_monthly'))->money('EGP'),
                    TextEntry::make('service_charge_monthly')->label(__('admin.fields.service_charge_monthly'))->money('EGP')->placeholder('—'),
                    TextEntry::make('billing_frequency')
                        ->label(__('admin.fields.billing_frequency'))
                        ->formatStateUsing(fn (?string $state) => $state ? __("admin.billing_frequency.{$state}") : '—'),
                    TextEntry::make('security_deposit')->label(__('admin.fields.security_deposit'))->money('EGP')->placeholder('—'),
                    // Only meaningful when a levy applies — spelled out as a rate the tenant can check.
                    TextEntry::make('marketing_levy_rate')
                        ->label(__('admin.fields.marketing_levy_rate'))
                        ->visible(fn (Lease $record) => (bool) $record->has_marketing_levy)
                        ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'),
                    TextEntry::make('escalation_rate')
                        ->label(__('admin.portal.lease.escalation'))
                        ->visible(fn (Lease $record) => (float) $record->escalation_rate > 0)
                        ->formatStateUsing(fn (Lease $record) => rtrim(rtrim(number_format((float) $record->escalation_rate, 2), '0'), '.').'% · '
                            .($record->next_escalation_date?->format('d/m/Y') ?? '—')),
                ]),

            // Percentage rent is only shown to the tenants it applies to — a plain-language summary
            // of the threshold + rate so they know when overage kicks in.
            Section::make(__('admin.portal.lease.percentage_rent'))
                ->visible(fn (Lease $record) => (bool) $record->has_percentage_rent)
                ->columns(3)
                ->components([
                    TextEntry::make('percentage_rent_rate')
                        ->label(__('admin.portal.lease.pct_rate'))
                        ->formatStateUsing(fn ($state) => rtrim(rtrim(number_format((float) $state, 2), '0'), '.').'%'),
                    TextEntry::make('percentage_rent_threshold')
                        ->label(__('admin.portal.lease.pct_threshold'))
                        ->money('EGP')
                        ->placeholder('—'),
                    TextEntry::make('percentage_rent_frequency')
                        ->label(__('admin.fields.percentage_rent_frequency'))
                        ->formatStateUsing(fn (?string $state) => __("admin.enums.percentage_rent_frequency.{$state}") !== "admin.enums.percentage_rent_frequency.{$state}"
                            ? __("admin.enums.percentage_rent_frequency.{$state}")
                            : __('admin.enums.percentage_rent_frequency.monthly')),
                ]),
        ]);
    }
}
