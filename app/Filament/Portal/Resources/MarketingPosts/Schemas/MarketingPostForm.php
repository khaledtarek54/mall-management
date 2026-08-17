<?php

namespace App\Filament\Portal\Resources\MarketingPosts\Schemas;

use App\Models\Asset;
use App\Models\MarketingPost;
use App\Support\Filament\EntitySelect;
use App\Support\Portal;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\SpatieMediaLibraryFileUpload;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

/**
 * What a retailer fills in. Deliberately smaller than the operator's form.
 *
 * Absent, and each on purpose: `status` (the Submit action owns it), `is_featured` and `priority`
 * (promoting yourself into the mall's carousel is not a retailer's decision), and the display
 * window (scheduling is the mall's lever — a retailer says when the offer is VALID, the mall
 * decides when to show the card). None of them is rendered-and-ignored; they are simply not here,
 * and the API action strips the same set, so neither surface can be talked into them.
 */
class MarketingPostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->columns(2)->components([
            Section::make(__('admin.marketing_posts.sections.what'))
                ->columns(2)
                ->schema([
                    EntitySelect::make('asset_id')
                        ->label(__('admin.marketing_posts.fields.property'))
                        ->entity(Asset::class)
                        // Only malls this retailer actually trades in.
                        //
                        // `units.allLeases` — the `lease_unit` PIVOT — not `units.leases`, which
                        // is a hasMany on the denormalized `leases.unit_id` and therefore finds
                        // only leases where the unit is the MASTER. A retailer whose presence in
                        // this mall is an additional unit on a multi-unit lease would be missing
                        // from the dropdown while the service guard and the mobile API both
                        // accept them — a mall they can post to from their phone but not from the
                        // portal. (CLAUDE.md names this exact trap: Unit uses `allLeases`.)
                        //
                        // This scopes the RENDERING only; the submitted value is re-checked by
                        // assertTenantTradesIn(), because Livewire state is attacker-controlled.
                        ->modifyOptionsQuery(fn ($query) => $query
                            ->whereHas('units.allLeases', fn ($q) => $q
                                ->where('leases.tenant_id', Portal::tenantId())
                                ->where('leases.status', 'active')))
                        ->required(),

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
                ]),

            Section::make(__('admin.marketing_posts.sections.copy'))
                ->columns(2)
                ->schema([
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
                        ->rows(4)
                        ->maxLength(5000),
                    Textarea::make('body_ar')
                        ->label(__('admin.marketing_posts.fields.body_ar'))
                        ->rows(4)
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
                ]),

            Section::make(__('admin.marketing_posts.sections.artwork'))
                ->columns(1)
                ->schema([
                    SpatieMediaLibraryFileUpload::make('hero')
                        ->label(__('admin.marketing_posts.fields.hero'))
                        ->helperText(__('admin.marketing_posts.fields.hero_hint'))
                        ->collection(MarketingPost::HERO_COLLECTION)
                        ->image()
                        ->imageEditor()
                        ->imageEditorAspectRatios(['16:9'])
                        ->maxSize(5120),
                    SpatieMediaLibraryFileUpload::make('gallery')
                        ->label(__('admin.marketing_posts.fields.gallery'))
                        ->collection(MarketingPost::GALLERY_COLLECTION)
                        ->image()
                        ->multiple()
                        ->maxFiles(6)
                        ->maxSize(5120),
                ]),

            Section::make(__('admin.marketing_posts.sections.when'))
                ->columns(2)
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

                    TextInput::make('cta_label')
                        ->label(__('admin.marketing_posts.fields.cta_label'))
                        ->maxLength(60),
                    TextInput::make('cta_url')
                        ->label(__('admin.marketing_posts.fields.cta_url'))
                        // url(), not a bare string — this becomes a tappable link in a shopper's
                        // app, and `javascript:` there is a stored-XSS delivery mechanism.
                        ->url()
                        ->maxLength(500),
                ]),
        ]);
    }
}
