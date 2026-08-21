<?php

namespace App\Filament\Portal\Resources\TenantRequests\Schemas;

use App\Enums\TenantRequestType;
use App\Models\TenantRequestSubcategory;
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
                        ->color(fn (string $state): string => match ($state) {
                            'submitted' => 'info',
                            'acknowledged' => 'warning',
                            'in_progress' => 'primary',
                            'awaiting_tenant' => 'warning',
                            'resolved' => 'success',
                            'closed' => 'gray',
                            'cancelled' => 'danger',
                            default => 'gray',
                        }),
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
                        ->color(fn (string $state): string => match ($state) {
                            'urgent' => 'danger',
                            'high' => 'warning',
                            'medium' => 'info',
                            default => 'gray',
                        }),
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
                ]),
        ]);
    }
}
