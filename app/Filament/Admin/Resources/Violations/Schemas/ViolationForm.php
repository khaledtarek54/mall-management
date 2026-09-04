<?php

namespace App\Filament\Admin\Resources\Violations\Schemas;

use App\Models\Tenant;
use App\Models\Violation;
use App\Models\ViolationCategory;
use App\Support\Filament\EntitySelect;
use App\Support\Filament\PropertyField;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;

class ViolationForm
{
    /**
     * FIXED BY THE FINE INVOICE THIS VIOLATION PRODUCED — named ONCE so the five fields it governs
     * cannot drift apart (SW-128).
     *
     * Measured against `BillViolationFineService::bill()`: the invoice's ONLY line is
     * `admin.violations.fine_line` — ":reference (:category) — :date" — composed from the
     * violation's reference, `ViolationCategory::labelFor($violation->category)` and
     * `$violation->violation_date->isoFormat('D MMM YYYY')`; and the invoice's `period_start` /
     * `period_end` are that same date's own month. The property, the tenant and the amount were
     * frozen when the action shipped and `category` and `violation_date` were not — three copies of
     * one predicate, and the two fields nobody copied it onto were the two the document QUOTES. A
     * billed violation could therefore be re-categorised or re-dated, and the register would then
     * say one thing while the document the tenant is holding said another, with nothing on either
     * to say they disagree.
     *
     * Frozen rather than propagated: an issued invoice is evidence. The correction is to cancel the
     * fine invoice — the one status that frees {@see Violation::isBilled()} — and bill it again.
     */
    private static function isBilled(?Violation $record): bool
    {
        return $record?->isBilled() ?? false;
    }

    /**
     * What a locked field says, and only while it is locked.
     *
     * A field that has silently stopped accepting input reads as a broken form, so the sentence
     * names the escape. Same shape as `LeaseForm`'s `billing_frequency_locked`: helper text reports
     * the STATE, which changes; the explanation of the FIELD, which does not, stays put.
     */
    private static function lockedHint(?Violation $record): ?string
    {
        return self::isBilled($record) ? __('admin.helpers.violation_locked_by_fine') : null;
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            // Additionally frozen once the fine is billed — the invoice's property is fixed by the
            // resolved lease, so the two would disagree.
            PropertyField::make(alsoDisabledWhen: fn (?Violation $record) => self::isBilled($record))
                ->label(__('admin.violations.fields.property'))
                ->live(),

            EntitySelect::make('tenant_id')
                ->label(__('admin.violations.fields.tenant'))
                // Scoped to tenants leasing in the user's visible properties (plus
                // unaffiliated tenants) — a restricted user is never offered another
                // mall's tenants. Same helper the TenantRequestForm uses.
                ->entity(Tenant::class)
                // The options exclude a tenant leasing only in another property (or soft-deleted),
                // yet the violation row stays openable via its own asset — resolve the stored tenant
                // so edit never shows the raw id.
                ->getOptionLabelUsing(fn ($value): ?string => Tenant::withTrashed()->find($value)?->name)
                ->searchable()
                ->preload()
                ->required()
                // The tenant is the invoice's debtor once billed — lock it so an edit can't re-point a
                // live fine invoice at a different tenant.
                ->disabled(fn (?Violation $record) => self::isBilled($record))
                ->helperText(fn (?Violation $record): ?string => self::lockedHint($record))
                ->native(false),

            Select::make('category')
                ->label(__('admin.violations.fields.category'))
                // The catalogue, not a const: a rule the operator added has no lang key and would
                // render as its raw code on the very screen that offers it.
                ->options(fn () => ViolationCategory::options())
                ->default('other')
                ->required()
                ->native(false)
                // The invoice line QUOTES this category's LABEL, so a re-categorised fine and the
                // document already served on the tenant would name two different breaches.
                ->disabled(fn (?Violation $record) => self::isBilled($record))
                ->helperText(fn (?Violation $record): ?string => self::lockedHint($record))
                // The house rules carry a tariff, and a field officer at the shop door should not be
                // recalling it. PREFILL only — it fills a blank and never overwrites a typed figure,
                // because what was actually charged is the operator's decision and the record of it.
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set, Get $get): void {
                    if ($state === null || filled($get('fine_amount'))) {
                        return;
                    }

                    $standard = ViolationCategory::defaultFineFor($state);

                    if ($standard !== null) {
                        $set('fine_amount', $standard);
                    }
                }),

            Textarea::make('description')
                ->label(__('admin.violations.fields.description'))
                ->required()
                ->rows(3)
                ->columnSpanFull(),

            // Photographic evidence of the breach — the thing that makes a violation defensible.
            // Private disk (declared on the model's media collection).
            SpatieMediaLibraryFileUpload::make('photos')
                ->label(__('admin.violations.fields.photos'))
                ->helperText(__('admin.violations.fields.photos_hint'))
                ->collection(Violation::PHOTOS_COLLECTION)
                ->image()
                ->multiple()
                ->reorderable()
                ->downloadable()
                ->maxFiles(8)
                ->columnSpanFull(),

            TextInput::make('fine_amount')
                ->label(__('admin.violations.fields.fine_amount'))
                // "recorded only, not billed" stops being true the moment it IS billed, so the
                // locked sentence REPLACES it rather than sitting under it contradicting it.
                ->helperText(fn (?Violation $record): string => self::lockedHint($record)
                    ?? __('admin.violations.fine_amount_hint'))
                // FR-REQ-15: record the associated cost/fine. Optional (a violation may carry no
                // fine) and non-negative.
                ->numeric()
                ->minValue(0)
                ->prefix('EGP')
                // Once the fine is billed, the amount is fixed by the issued invoice — editing it here
                // would silently diverge the record from the AR. Cancel the invoice to change it.
                ->disabled(fn (?Violation $record) => self::isBilled($record)),

            DatePicker::make('violation_date')
                ->label(__('admin.violations.fields.violation_date'))
                ->required()
                ->default(now())
                // A violation happened on or before today — never in the future.
                ->maxDate(now())
                // The invoice line quotes this date AND the invoice's period is its own month, so
                // re-dating a billed fine moves the document's accounting period out from under it.
                ->disabled(fn (?Violation $record) => self::isBilled($record))
                ->helperText(fn (?Violation $record): ?string => self::lockedHint($record))
                ->native(false),

            Select::make('status')
                ->label(__('admin.violations.fields.status'))
                ->options(fn () => collect(Violation::STATUSES)
                    ->mapWithKeys(fn (string $s) => [$s => __("admin.statuses.violation.$s")]))
                ->default(Violation::STATUS_OPEN)
                ->selectablePlaceholder(false)
                ->required()
                ->native(false),

            Textarea::make('notes')
                ->label(__('admin.violations.fields.notes'))
                ->rows(2)
                ->columnSpanFull(),
        ]);
    }
}
