<?php

namespace App\Filament\Portal\Resources\TenantRequests\Schemas;

use App\Enums\TenantRequestType;
use App\Models\TenantRequestSubcategory;
use App\Support\BadgeColors;
use App\Support\Filament\PrivateAttachments;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class TenantRequestInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(1)->components([
            Section::make(__('admin.sections.request'))
                ->columns(3)
                ->components([
                    TextEntry::make('reference')
                        ->label(__('admin.fields.reference'))
                        ->copyable()
                        ->fontFamily('mono'),
                    TextEntry::make('unit.code')
                        ->label(__('admin.fields.unit_label'))
                        ->badge()
                        ->color('gray'),
                    TextEntry::make('status')
                        ->label(__('admin.tables.common.status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.statuses.tenant_request.{$state}"))
                        ->color(BadgeColors::of('tenant_requests.status')),
                    TextEntry::make('category')
                        ->label(__('admin.fields.category'))
                        ->badge()
                        ->color('gray')
                        ->formatStateUsing(fn (?string $state, $record) => TenantRequestSubcategory::labelFor(
                            $state,
                            $record->request_type instanceof TenantRequestType ? $record->request_type : null,
                        )),
                    TextEntry::make('priority')
                        ->label(__('admin.fields.priority'))
                        ->badge()
                        ->formatStateUsing(fn (string $state) => __("admin.enums.work_priority.{$state}"))
                        ->color(BadgeColors::of('tenant_requests.priority')),
                    TextEntry::make('submitted_at')
                        ->label(__('admin.tables.requests.submitted'))
                        ->dateTime('d/m/Y H:i'),
                ]),
            Section::make(__('admin.sections.request_details'))
                ->components([
                    TextEntry::make('title')
                        ->label(__('admin.fields.request_title'))
                        ->weight('bold'),
                    TextEntry::make('description')
                        ->label(__('admin.fields.description'))
                        ->columnSpanFull(),
                    // What the tenant attached. The portal FORM collects up to five images or PDFs
                    // and no portal screen showed one back — there is no Edit page here either — so
                    // a retailer could not check what they had sent us, while `/api/v1` has
                    // returned the list with a per-file URL since it shipped.
                    PrivateAttachments::entry('attachments', __('admin.fields.attachments')),
                ]),
            Section::make(__('admin.sections.resolution'))
                ->visible(fn ($record) => filled($record->resolution_notes))
                ->components([
                    TextEntry::make('resolved_at')
                        ->label(__('admin.fields.resolved_at'))
                        ->dateTime('d/m/Y H:i'),
                    TextEntry::make('resolution_notes')
                        ->label(__('admin.fields.resolution_notes'))
                        ->columnSpanFull(),
                    // The tenant sees the proof, not just the claim. A maintenance request cannot
                    // be resolved without it (SW-246), and showing it here is what makes the
                    // requirement worth anything to the person who reported the fault — the same
                    // reason their own `attachments` are shown above.
                    PrivateAttachments::entry(
                        'resolution_evidence',
                        __('admin.tenant_requests.fields.resolution_evidence'),
                    ),
                ]),
        ]);
    }
}
