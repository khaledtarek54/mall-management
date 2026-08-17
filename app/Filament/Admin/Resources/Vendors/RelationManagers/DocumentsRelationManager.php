<?php

namespace App\Filament\Admin\Resources\Vendors\RelationManagers;

use App\Models\VendorDocument;
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
 * Vendor compliance documents (module 12b) — replaces the three fixed COI fields that used to sit
 * on the vendor form. An Egyptian supplier file is several documents, each expiring on its own
 * clock: insurance, بطاقة ضريبية, سجل تجاري, شهادة تأمينات اجتماعية.
 */
class DocumentsRelationManager extends RelationManager
{
    protected static string $relationship = 'documents';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('admin.vendors.documents.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Select::make('type')
                ->label(__('admin.vendors.documents.type'))
                ->options(fn () => __('admin.vendors.documents.types'))
                ->default(VendorDocument::TYPE_INSURANCE_COI)
                ->helperText(__('admin.vendors.documents.type_hint'))
                ->required()
                ->native(false),
            TextInput::make('reference')
                ->label(__('admin.vendors.documents.reference'))
                ->maxLength(100),
            TextInput::make('issuer')
                ->label(__('admin.vendors.documents.issuer'))
                ->maxLength(200),
            DatePicker::make('issued_on')
                ->label(__('admin.vendors.documents.issued_on'))
                ->native(false),
            DatePicker::make('expires_on')
                ->label(__('admin.vendors.documents.expires_on'))
                ->helperText(__('admin.vendors.documents.expires_on_hint'))
                ->after('issued_on')
                ->native(false),
            SpatieMediaLibraryFileUpload::make('file')
                ->label(__('admin.vendors.documents.file'))
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
            // No search box: VendorDocument carries no `search_text` blob (it is not a
            // record anyone hunts for by name) and this table marks no column
            // searchable. Without this, TableDefaults' blob search would still render
            // the box — and a search box that always returns nothing is worse than
            // none, because it reads as "no such row". See App\Support\SearchPolicy.
            ->searchable(false)
            ->columns([
                TextColumn::make('type')
                    ->label(__('admin.vendors.documents.type'))
                    ->badge()
                    ->formatStateUsing(fn (string $state) => __("admin.vendors.documents.types.{$state}"))
                    // Only a blocking type stops work — say so, rather than making the
                    // operator remember which documents are which.
                    ->color(fn (VendorDocument $record) => $record->isBlocking() ? 'primary' : 'gray')
                    ->description(fn (VendorDocument $record) => $record->isBlocking()
                        ? __('admin.vendors.documents.blocking')
                        : null),
                TextColumn::make('reference')->label(__('admin.vendors.documents.reference'))->placeholder('—'),
                TextColumn::make('issuer')->label(__('admin.vendors.documents.issuer'))->placeholder('—')->toggleable(),
                TextColumn::make('issued_on')->label(__('admin.vendors.documents.issued_on'))->date('d/m/Y')->placeholder('—')->toggleable(),
                TextColumn::make('expires_on')
                    ->label(__('admin.vendors.documents.expires_on'))
                    ->date('d/m/Y')
                    ->sortable()
                    ->placeholder(__('admin.vendors.documents.no_expiry'))
                    ->badge()
                    ->color(fn (VendorDocument $record) => match ($record->alertStage()) {
                        VendorDocument::STAGE_EXPIRED => 'danger',
                        VendorDocument::STAGE_EXPIRING => 'warning',
                        default => $record->expires_on === null ? 'gray' : 'success',
                    })
                    // The consequence, not just the date — an expired insurance certificate
                    // silently removed the vendor from every picker before this.
                    ->description(fn (VendorDocument $record) => match (true) {
                        $record->alertStage() === VendorDocument::STAGE_EXPIRED && $record->isBlocking() => __('admin.vendors.documents.expired_blocking'),
                        $record->alertStage() === VendorDocument::STAGE_EXPIRED => __('admin.vendors.documents.expired_nonblocking'),
                        $record->alertStage() === VendorDocument::STAGE_EXPIRING => __('admin.vendors.documents.expiring_in', ['days' => (int) $record->daysToExpiry()]),
                        default => null,
                    }),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->label(__('admin.vendors.documents.type'))
                    ->options(fn () => __('admin.vendors.documents.types')),
                Filter::make('needs_attention')
                    ->label(__('admin.filters.document_attention'))
                    ->query(function (Builder $query): Builder {
                        /** @var Builder<VendorDocument> $query */
                        return $query->needsAttention();
                    })
                    ->toggle(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('admin.vendors.documents.add'))
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('vendors.edit') ?? false),
            ])
            ->recordActions([
                EditAction::make()
                    ->visible(fn () => auth()->user()?->can('vendors.edit') ?? false)
                    ->authorize(fn () => auth()->user()?->can('vendors.edit') ?? false),
                DeleteAction::make()
                    ->visible(fn () => auth()->user()?->hasRole('super_admin') ?? false)
                    ->authorize(fn () => auth()->user()?->hasRole('super_admin') ?? false),
            ])
            ->defaultSort('expires_on')
            ->emptyStateIcon('heroicon-o-document-check')
            ->emptyStateHeading(__('admin.vendors.documents.empty_heading'))
            ->emptyStateDescription(__('admin.vendors.documents.empty_description'));
    }
}
