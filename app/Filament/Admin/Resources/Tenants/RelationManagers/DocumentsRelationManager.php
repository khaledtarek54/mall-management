<?php

namespace App\Filament\Admin\Resources\Tenants\RelationManagers;

use App\Models\TenantDocument;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Tenant compliance documents — the certificate of insurance above all (Yardi gap row 92).
 *
 * The vendor equivalent has existed since module 12b; the tenant side did not, which is the wrong
 * way round if you only get one. A retailer trading uninsured on the mall floor is the operator's
 * liability, and the lease almost always obliges them to carry cover naming the landlord.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.tenants.documents.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')
                ->label(__('admin.tenants.documents.type'))
                ->options(fn () => TenantDocument::typeOptions())
                ->default(TenantDocument::TYPE_INSURANCE_COI)
                ->required()
                ->native(false),
            TextInput::make('reference')
                ->label(__('admin.tenants.documents.reference'))
                ->maxLength(100),
            TextInput::make('issuer')
                ->label(__('admin.tenants.documents.issuer'))
                ->maxLength(200),
            TextInput::make('coverage_amount')
                ->label(__('admin.tenants.documents.coverage_amount'))
                ->prefix('EGP')
                ->numeric()
                ->minValue(0)
                // The certificate is only worth holding if the sum insured is the one the lease
                // demanded — that is the number an operator actually compares.
                ->helperText(__('admin.tenants.documents.coverage_amount_hint')),
            DatePicker::make('issued_on')
                ->label(__('admin.tenants.documents.issued_on'))
                ->native(false),
            DatePicker::make('expires_on')
                ->label(__('admin.tenants.documents.expires_on'))
                ->helperText(__('admin.tenants.documents.expires_on_hint'))
                ->after('issued_on')
                ->native(false),
            SpatieMediaLibraryFileUpload::make('file')
                ->label(__('admin.tenants.documents.file'))
                ->collection('file')
                ->downloadable()
                ->acceptedFileTypes(['application/pdf', 'image/*'])
                ->columnSpanFull(),
            Textarea::make('notes')
                ->label(__('admin.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            // No search box: TenantDocument carries no `search_text` blob (nobody hunts for a
            // certificate by name) and no column here is searchable. Without this, TableDefaults'
            // blob search would still render the box — and a box that always returns nothing reads
            // as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.tenants.documents.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.enums.tenant_document_type.{$state}"))
                    ->color(fn (TenantDocument $record) => $record->type === TenantDocument::TYPE_INSURANCE_COI
                        ? 'primary'
                        : 'gray'),
                TextColumn::make('reference')->label(__('admin.tenants.documents.reference'))->placeholder('—'),
                TextColumn::make('issuer')->label(__('admin.tenants.documents.issuer'))->placeholder('—')->toggleable(),
                TextColumn::make('coverage_amount')
                    ->label(__('admin.tenants.documents.coverage_amount'))
                    ->money('EGP')
                    ->placeholder('—')
                    ->toggleable(),
                TextColumn::make('issued_on')->label(__('admin.tenants.documents.issued_on'))->date('d/m/Y')->placeholder('—')->toggleable(),
                TextColumn::make('expires_on')
                    ->label(__('admin.tenants.documents.expires_on'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder(__('admin.tenants.documents.no_expiry'))
                    ->badge()
                    ->color(fn (TenantDocument $record) => match ($record->alertStage()) {
                        TenantDocument::STAGE_EXPIRED => 'danger',
                        TenantDocument::STAGE_EXPIRING => 'warning',
                        default => $record->expires_on === null ? 'gray' : 'success',
                    })
                    ->description(fn (TenantDocument $record) => match ($record->alertStage()) {
                        TenantDocument::STAGE_EXPIRED => __('admin.tenants.documents.expired'),
                        TenantDocument::STAGE_EXPIRING => __('admin.tenants.documents.expiring_in', [
                            'days' => (int) $record->daysToExpiry(),
                        ]),
                        default => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.tenants.documents.type'))
                    ->options(fn () => TenantDocument::typeOptions()),
                Filter::make('needs_attention')
                    ->label(__('admin.filters.document_attention'))
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<TenantDocument> $query */
                        return $query->needsAttention();
                    })
                    ->toggle(),
            ])
            ->headerActions([
                // Double-gated in visible() AND authorize() — the project invariant. The predicate
                // is named once so the two cannot drift.
                CreateAction::make()
                    ->label(__('admin.tenants.documents.add'))
                    ->modalHeading(__('admin.tenants.documents.add'))
                    ->visible(fn () => static::canWrite())
                    ->authorize(fn () => static::canWrite()),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => static::canWrite())
                    ->authorize(fn () => static::canWrite()),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn () => auth()->user()?->hasRole('super_admin') ?? false),
            ])
            ->defaultSort('expires_on')
            ->emptyStateIcon('heroicon-o-document-check')
            ->emptyStateHeading(__('admin.tenants.documents.empty_heading'))
            ->emptyStateDescription(__('admin.tenants.documents.empty_description'));
    }

    /** Writing a tenant's paperwork is editing the tenant — no separate permission module. */
    protected static function canWrite(): bool
    {
        return auth()->user()?->can('tenants.edit') ?? false;
    }
}
