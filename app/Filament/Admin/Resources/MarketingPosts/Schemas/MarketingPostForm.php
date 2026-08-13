<?php

namespace App\Filament\Admin\Resources\MarketingPosts\Schemas;

use App\Filament\Admin\Resources\MarketingPosts\MarketingPostResource;
use App\Models\MarketingPost;
use App\Models\Tenant;
use App\Support\FormTab;
use App\Support\TenantScope;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Schema;

class MarketingPostForm
{
    public static function configure(Schema $schema): Schema
    {
        // Thirty fields across five concerns is a scroll, not a form — the same problem the lease,
        // invoice, tenant, credit-note, payment and request forms were converted for (operator
        // decision 2026-08-08). Built through App\Support\FormTab so each tab carries a badge
        // counting the validation errors inside it: Filament v4 ships no error indicator on Tabs,
        // and without one a required field left blank on a tab you are not looking at refuses the
        // form with nothing visible to fix.
        return $schema->columns(1)->components([
            Tabs::make('marketing_post')
                ->columnSpanFull()
                ->persistTabInQueryString()
                ->tabs([
            FormTab::make('admin.marketing_posts.sections.what', [
                    Select::make('asset_id')
                        ->label(__('admin.marketing_posts.fields.property'))
                        // Scoped to the user's visible properties (never offers another mall).
                        ->options(fn () => TenantScope::selectableAssetOptions())
                        ->default(fn () => TenantScope::currentAssetId())
                        ->disabled(fn () => TenantScope::currentAssetId() !== null)
                        ->dehydrated()
                        ->required()
                        ->native(false)
                        ->live(),

                    Select::make('tenant_id')
                        ->label(__('admin.marketing_posts.fields.store'))
                        ->helperText(__('admin.marketing_posts.fields.store_hint'))
                        // Only retailers who actually trade in the chosen mall — through the
                        // lease_unit pivot, so a multi-unit lease's additional property counts.
                        // A null property offers nothing rather than the whole portfolio.
                        ->options(function (callable $get) {
                            $assetId = $get('asset_id');

                            if (! $assetId) {
                                return [];
                            }

                            return Tenant::query()
                                ->whereHas('activeLeases.units', fn ($q) => $q->where('units.asset_id', $assetId))
                                ->orderBy('name')
                                ->get()
                                ->mapWithKeys(fn (Tenant $t) => [$t->id => $t->storeName()])
                                ->all();
                        })
                        ->searchable()
                        ->native(false)
                        // Nullable: a mall-wide post ("late-night shopping in Ramadan") has no store.
                        ->placeholder(__('admin.marketing_posts.mall_wide')),

                    Select::make('type')
                        ->label(__('admin.fields.type'))
                        ->options(fn () => collect(MarketingPost::TYPES)
                            ->mapWithKeys(fn ($t) => [$t => __("admin.marketing_posts.types.{$t}")]))
                        ->default(MarketingPost::TYPE_OFFER)
                        ->required()
                        ->native(false),

                    Select::make('audience')
                        ->label(__('admin.marketing_posts.fields.audience'))
                        ->helperText(__('admin.marketing_posts.fields.audience_hint'))
                        ->options(fn () => collect(MarketingPost::AUDIENCES)
                            ->mapWithKeys(fn ($a) => [$a => __("admin.marketing_posts.audiences.{$a}")]))
                        ->default(MarketingPost::AUDIENCE_VISITORS)
                        ->required()
                        ->native(false),
            ])->columns(2),

            FormTab::make('admin.marketing_posts.sections.copy', [
                    TextInput::make('title')
                        ->label(__('admin.marketing_posts.fields.title'))
                        ->required()
                        ->maxLength(255),
                    TextInput::make('title_ar')
                        ->label(__('admin.marketing_posts.fields.title_ar'))
                        ->maxLength(255),

                    TextInput::make('discount_label')
                        ->label(__('admin.marketing_posts.fields.discount_label'))
                        ->helperText(__('admin.marketing_posts.fields.discount_label_hint'))
                        ->maxLength(60),
                    TextInput::make('discount_label_ar')
                        ->label(__('admin.marketing_posts.fields.discount_label_ar'))
                        ->maxLength(60),

                    Textarea::make('summary')
                        ->label(__('admin.marketing_posts.fields.summary'))
                        ->rows(2)
                        ->maxLength(500),
                    Textarea::make('summary_ar')
                        ->label(__('admin.marketing_posts.fields.summary_ar'))
                        ->rows(2)
                        ->maxLength(500),

                    Textarea::make('body')
                        ->label(__('admin.marketing_posts.fields.body'))
                        ->rows(5)
                        ->maxLength(5000),
                    Textarea::make('body_ar')
                        ->label(__('admin.marketing_posts.fields.body_ar'))
                        ->rows(5)
                        ->maxLength(5000),

                    Textarea::make('terms')
                        ->label(__('admin.marketing_posts.fields.terms'))
                        ->helperText(__('admin.marketing_posts.fields.terms_hint'))
                        ->rows(2)
                        ->maxLength(2000),
                    Textarea::make('terms_ar')
                        ->label(__('admin.marketing_posts.fields.terms_ar'))
                        ->rows(2)
                        ->maxLength(2000),
            ])->columns(2),

            FormTab::make('admin.marketing_posts.sections.artwork', [
                    SpatieMediaLibraryFileUpload::make('hero')
                        ->label(__('admin.marketing_posts.fields.hero'))
                        ->helperText(__('admin.marketing_posts.fields.hero_hint'))
                        ->collection(MarketingPost::HERO_COLLECTION)
                        ->image()
                        ->imageEditor()
                        // The card is a wide banner in every mall app; cropping to it here beats
                        // discovering the crop on a shopper's phone.
                        ->imageEditorAspectRatios(['16:9'])
                        ->maxSize(5120),

                    SpatieMediaLibraryFileUpload::make('gallery')
                        ->label(__('admin.marketing_posts.fields.gallery'))
                        ->collection(MarketingPost::GALLERY_COLLECTION)
                        ->image()
                        ->multiple()
                        ->reorderable()
                        ->maxFiles(6)
                        ->maxSize(5120),
            ])->columns(1),

            FormTab::make('admin.marketing_posts.sections.when', [
                // Kept as a Section purely to carry its description. A Tab has nowhere to put one,
                // and this particular sentence is what stops the two windows being confused — the
                // validity window is the promise made to the shopper, the display window is when the
                // card is on screen. Losing it would make the tabbed form worse than the scroll it
                // replaced.
                Section::make()
                    ->description(__('admin.marketing_posts.sections.when_hint'))
                    ->columns(2)
                    ->columnSpanFull()
                    ->schema([
                    DateTimePicker::make('starts_at')
                        ->label(__('admin.marketing_posts.fields.starts_at'))
                        ->helperText(__('admin.marketing_posts.fields.starts_at_hint'))
                        ->seconds(false)
                        ->native(false),
                    DateTimePicker::make('ends_at')
                        ->label(__('admin.marketing_posts.fields.ends_at'))
                        ->helperText(__('admin.marketing_posts.fields.ends_at_hint'))
                        ->seconds(false)
                        ->native(false)
                        ->after('starts_at'),

                    DateTimePicker::make('display_from')
                        ->label(__('admin.marketing_posts.fields.display_from'))
                        ->helperText(__('admin.marketing_posts.fields.display_hint'))
                        ->seconds(false)
                        ->native(false),
                    DateTimePicker::make('display_until')
                        ->label(__('admin.marketing_posts.fields.display_until'))
                        ->helperText(__('admin.marketing_posts.fields.display_hint'))
                        ->seconds(false)
                        ->native(false)
                        ->after('display_from'),
                    ]),
            ])->columns(2),

            FormTab::make('admin.marketing_posts.sections.placement', [
                    Toggle::make('is_featured')
                        ->label(__('admin.marketing_posts.fields.is_featured'))
                        ->helperText(__('admin.marketing_posts.fields.is_featured_hint'))
                        // Promoting a post into the mall's carousel is a publishing decision, not
                        // a copy edit — gated on the same authority as approving one. Disabled
                        // rather than hidden so an editor can SEE the field exists and why the
                        // value is what it is.
                        ->disabled(fn () => ! MarketingPostResource::canApprove())
                        ->dehydrated(fn () => MarketingPostResource::canApprove()),
                    TextInput::make('priority')
                        ->label(__('admin.marketing_posts.fields.priority'))
                        ->helperText(__('admin.marketing_posts.fields.priority_hint'))
                        ->numeric()
                        ->default(0)
                        ->minValue(0)
                        ->maxValue(1000)
                        ->disabled(fn () => ! MarketingPostResource::canApprove())
                        ->dehydrated(fn () => MarketingPostResource::canApprove()),

                    TextInput::make('cta_label')
                        ->label(__('admin.marketing_posts.fields.cta_label'))
                        ->maxLength(60),
                    TextInput::make('cta_label_ar')
                        ->label(__('admin.marketing_posts.fields.cta_label_ar'))
                        ->maxLength(60),
                    TextInput::make('cta_url')
                        ->label(__('admin.marketing_posts.fields.cta_url'))
                        // url(), not a bare string: this renders as a tappable link in a shopper's
                        // app, and `javascript:` in that slot is a stored-XSS delivery mechanism.
                        ->url()
                        ->maxLength(500)
                        ->columnSpanFull(),
            ])->columns(2),
                ]),
        ]);
    }
}
