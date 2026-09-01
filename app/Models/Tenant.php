<?php

namespace App\Models;

use App\Enums\PartyType;
use App\Models\Concerns\AllocatesPartyCode;
use App\Models\Concerns\HasCustomFields;
use App\Models\Concerns\HasSearchText;
use App\Models\Concerns\RefusesDeletionWhenReferenced;
use App\Notifications\TenantResetPasswordNotification;
use App\Support\ActivityLogging;
use App\Support\Attributes\DeletableWhenUnused;
use App\Support\Attributes\PortfolioShared;
use App\Support\MarketingFeedCache;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Auth\Passwords\CanResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[DeletableWhenUnused(blockedBy: ['leases', 'invoices', 'payments', 'creditNotes', 'salesDeclarations', 'tenantRequests', 'postDatedCheques', 'violations'], instead: 'set the tenant to inactive — the history stays queryable and the AR still ties out')]
// a retailer can lease in several malls; money is per-property (Invoice/Payment)
#[PortfolioShared]
class Tenant extends Authenticatable implements CanResetPasswordContract, FilamentUser, HasLocalePreference, HasMedia
{
    use AllocatesPartyCode, CanResetPassword, HasApiTokens, HasFactory, HasSearchText, InteractsWithMedia, LogsActivity, Notifiable, RefusesDeletionWhenReferenced, SoftDeletes;
    use HasCustomFields;

    /** Identity paperwork — commercial register, tax card, trade licence. */
    public const DOCUMENTS_COLLECTION = 'documents';

    /** The store's brand mark, shown to shoppers in the directory and on every offer card. */
    public const LOGO_COLLECTION = 'logo';

    /**
     * How a shopper browses the mall. String-backed (no DB enum, house rule) and deliberately
     * short: a directory with forty categories is a directory nobody filters. Extend by adding
     * here — the form and the public API both read this list.
     *
     * @var array<int, string>
     */
    public const RETAIL_CATEGORIES = [
        'fashion',
        'food_beverage',
        'electronics',
        'health_beauty',
        'home_lifestyle',
        'kids_toys',
        'sports',
        'jewellery_accessories',
        'entertainment',
        'services',
        'hypermarket',
        'other',
    ];

    /**
     * Trade name, legal name and the contact a leasing officer actually knows, plus the
     * business identifiers on the file. `national_id` is deliberately absent: it identifies a
     * person, not a business, and nobody hunts a retailer by it.
     *
     * The shopper-facing trade name is here too, in both languages — an operator hunting the
     * Defacto lease types the sign above the door, not the LLC on the contract.
     *
     * @return array<int, string|int|float|null>
     */
    public function searchTextSources(): array
    {
        return [
            $this->code,
            $this->name,
            $this->legal_name,
            $this->trade_name,
            $this->trade_name_ar,
            $this->contact_person,
            $this->email,
            $this->phone,
            $this->whatsapp,
            $this->tax_id,
            $this->commercial_register,
            self::digitsOf($this->phone),
            self::digitsOf($this->whatsapp),
            self::digitsOf($this->contact_person_phone),

            // The operator's own fields (D-7). `metadata` is this row's own attribute, so this
            // honours the no-relations rule and re-folds whenever the record saves.
            ...$this->customFieldSearchValues(),
        ];
    }

    /**
     * Tenant documents live on a PRIVATE disk (not web-accessible). These are the
     * retailer's identity papers — commercial register (سجل تجاري), tax card (بطاقة
     * ضريبية) — and leaking them is a data-protection incident, not just a bug.
     *
     * **This was a live exposure until 2026-07-16** — see the note on
     * {@see Lease::registerMediaCollections()}. Declare the disk explicitly; never inherit
     * medialibrary's `public` default (MediaPrivacyConformanceTest enforces it).
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::DOCUMENTS_COLLECTION)->useDisk('local');

        // The store logo is the ONE public thing about a retailer. It is a brand mark the shop
        // already displays on its own shutter and hands to anyone who asks — the same category as
        // a property's logo, and the same reason it may sit on the public disk: the shopper
        // fetching the directory is unauthenticated by design. Listed in
        // MediaPrivacyConformanceTest's PUBLIC_COLLECTIONS with that reason. It shares a model
        // with `documents`, which stays private — the collections are separate for exactly this.
        $this->addMediaCollection(self::LOGO_COLLECTION)->useDisk('public')->singleFile();
    }

    public function getActivitylogOptions(): LogOptions
    {
        return ActivityLogging::for($this, 'tenant');
    }

    protected $fillable = [
        // The operator's own fields (D-7). A VIRTUAL attribute — `HasCustomFields` routes it
        // through `fillCustomFields()`, which discards keys the catalogue does not define. The
        // `metadata` column itself is deliberately NOT fillable: nothing fills it wholesale.
        'custom_fields',
        // The retailer's own number — quotable, unique, and the thing an operator types to mean
        // exactly one tenant. Allocated by AllocatesPartyCode when blank; a code carried in from
        // another system is kept as-is.
        'code',
        'name',
        'legal_name',
        'type',
        // Which kind of AR party this row is — a retailer who leases, or a buyer who owns a unit.
        // See App\Enums\PartyType for why both live in one table.
        'party_type',
        'email',
        // The language this retailer's DOCUMENTS are issued in — the invoice, the credit note, the
        // receipt, the statement of account. The column shipped 2026-08-12 with the notification
        // preference and was fillable on nothing and written by no screen, so `preferredLocale()`
        // answered null for every tenant that has ever existed: the mechanism was present and
        // inert. `/locale/{locale}` persists the signed-in USER's choice, which is a person's
        // reading preference and a different fact from the one a company's accountant files under.
        // Null means "no preference stated" and resolves to whoever is asking.
        'locale',
        'password',
        'phone',
        'whatsapp',
        'tax_id',
        'national_id',
        'commercial_register',
        'address',
        'address_governorate',
        'address_city',
        'address_street',
        'address_building_number',
        'contact_person',
        'contact_person_phone',
        'status',
        // ---- Store directory (module 36): who this retailer is to a SHOPPER.
        'trade_name',
        'trade_name_ar',
        'retail_category',
        'public_description',
        'public_description_ar',
        'website_url',
        'instagram_handle',
        'is_listed',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'national_id',
        'tax_id',
    ];

    /** `is_listed` is NOT NULL; an unrendered form field must not send null into it. */
    protected $attributes = [
        'is_listed' => true,
        // Mirrors the column default so a NEW instance already reads `retailer` rather than null —
        // every existing creation path omits it, and code asking `isUnitOwner()` on the object a
        // create returns must not get a null answer to a question with two real answers.
        'party_type' => 'retailer',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_listed' => 'boolean',
            'party_type' => PartyType::class,
        ];
    }

    /**
     * Units this party BOUGHT — empty for a retailer.
     *
     * @return HasMany<UnitOwnership, $this>
     */
    public function unitOwnerships(): HasMany
    {
        return $this->hasMany(UnitOwnership::class);
    }

    /** Retailers only — the parties who lease space and declare sales. */
    public function scopeRetailers(Builder $query): void
    {
        $query->where('party_type', PartyType::Retailer->value);
    }

    /**
     * Unit owners only — مُلّاك الوحدات.
     *
     * Filtered on the column rather than on "has an ownership row", deliberately: a buyer is a unit
     * owner from the moment he is recorded, before any unit is assigned to him, and a party who
     * sold his last unit is still the counterparty on the arrears he left behind.
     */
    public function scopeUnitOwners(Builder $query): void
    {
        $query->where('party_type', PartyType::UnitOwner->value);
    }

    /** Is this party a unit owner? Asked of the enum so a third party kind cannot be forgotten. */
    public function isUnitOwner(): bool
    {
        return $this->party_type === PartyType::UnitOwner;
    }

    // ============ Store directory ============

    /**
     * The unit code(s) this retailer occupies in the ONE mall currently being browsed — set by the
     * public feed/directory endpoints, read by `PublicStoreResource`. Never persisted: there is no
     * such column, and there should not be, because the value is a function of which mall the
     * question was asked about.
     *
     * **A real declared property, not a magic attribute.** Assigning an undeclared name on an
     * Eloquent model routes through `__set` → `setAttribute()` and lands in `$attributes`, where it
     * would be handed to the next `save()` as a column that does not exist. It worked only because
     * nothing on these read paths saves. A declared property bypasses `__set` entirely, so the
     * hazard is gone rather than merely unexercised — and `isset()` behaves identically (null →
     * false → the resource omits the key), which is what the directory tests pin.
     *
     * @var array<int, string>|null
     */
    public ?array $public_locations = null;

    /**
     * A directory change invalidates the shopper caches that quote this retailer.
     *
     * Narrowed to the fields a shopper can actually see: a tenant row is saved constantly by
     * leasing and billing work, and bumping the public cache on every one of those would make the
     * cache pointless. `status` is in the list because an inactive retailer drops out of the
     * directory and off their own offer cards — see MarketingPost::liveFor().
     */
    /** Numbered from the operator-configurable `tenant` prefix — see AllocatesPartyCode. */
    public static function partyCodeType(): string
    {
        return 'tenant';
    }

    protected static function booted(): void
    {
        static::saved(function (self $tenant): void {
            $watched = [
                'trade_name', 'trade_name_ar', 'retail_category',
                'public_description', 'public_description_ar',
                'website_url', 'instagram_handle', 'is_listed', 'status',
            ];

            if ($tenant->wasChanged($watched)) {
                MarketingFeedCache::bumpForTenant((int) $tenant->getKey());
            }
        });
    }

    /**
     * The name a shopper is shown. Falls back to `name` so the directory works before anyone
     * fills the new field in — the alternative was blank cards on day one of the visitor app.
     */
    public function storeName(?string $locale = null): string
    {
        $arabic = ($locale ?? app()->getLocale()) === 'ar';

        if ($arabic && filled($this->trade_name_ar)) {
            return $this->trade_name_ar;
        }

        return $this->trade_name ?: $this->name;
    }

    public function logoUrl(): ?string
    {
        return $this->getFirstMedia(self::LOGO_COLLECTION)?->getFullUrl();
    }

    /**
     * Shopper-facing content this retailer runs.
     *
     * @return HasMany<MarketingPost, $this>
     */
    public function marketingPosts(): HasMany
    {
        return $this->hasMany(MarketingPost::class);
    }

    /**
     * Compliance paperwork held on file — insurance certificate, tax card, commercial register.
     *
     * @return HasMany<TenantDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(TenantDocument::class);
    }

    /**
     * Documents lapsed or lapsing inside the chase window.
     *
     * Read live off the rows rather than off the alert stamp: the stamp records what was *notified*,
     * and a dropped notification must never be able to make a lapsed certificate invisible on the
     * screen. Same separation the vendor chase draws.
     *
     * @return Collection<int, TenantDocument>
     */
    public function documentsNeedAttention(?Carbon $on = null): Collection
    {
        return $this->documents()->needsAttention($on)->orderBy('expires_on')->get();
    }

    /** Is there a current, unexpired insurance certificate on file? */
    public function hasCurrentInsurance(?Carbon $on = null): bool
    {
        return $this->documents()
            ->ofType(TenantDocument::TYPE_INSURANCE_COI)
            ->whereNotNull('expires_on')
            ->whereDate('expires_on', '>=', ($on ?? Carbon::today())->startOfDay()->toDateString())
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $panel->getId() === 'portal' && $this->status === 'active';
    }

    /** @return HasMany<Lease, $this> */
    public function leases(): HasMany
    {
        return $this->hasMany(Lease::class);
    }

    /**
     * Portal login accounts for this tenant (req #9 multi-user).
     *
     * @return HasMany<TenantUser, $this>
     */
    public function users(): HasMany
    {
        return $this->hasMany(TenantUser::class);
    }

    /**
     * Notify the tenant on every surface: the Tenant record (the mobile API
     * still authenticates it) AND each portal user (the web bell reads
     * TenantUser notifications). Tenants with no portal users still get the
     * Tenant copy, so nothing regresses.
     */
    public function notifyPortal($notification): void
    {
        $this->notify($notification);

        foreach ($this->users as $user) {
            $user->notify($notification);
        }
    }

    /** @return HasMany<Lease, $this> */
    public function activeLeases(): HasMany
    {
        return $this->leases()->where('status', 'active');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    // NEVER-deletable money records a tenant can hold before any invoice — a year of post-dated
    // cheques lodged up front. Listed so DeletionPolicy blocks deleting a tenant that holds them
    // (pre-go-live review).
    public function postDatedCheques(): HasMany
    {
        return $this->hasMany(PostDatedCheque::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function notes(): MorphMany
    {
        return $this->morphMany(Note::class, 'noteable')->latest('contacted_at');
    }

    public function tenantRequests(): HasMany
    {
        return $this->hasMany(TenantRequest::class);
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(CreditNote::class);
    }

    /**
     * Fines issued against this tenant. Directly tenant-scoped (the violations table has no
     * lease_id — only tenant_id + asset_id), so a tenant can carry violations with no active
     * lease; that is why it blocks deletion. The FK is restrictOnDelete, so without this blocker
     * the operator gets a raw database constraint error instead of the friendly refusal.
     *
     * @return HasMany<Violation, $this>
     */
    public function violations(): HasMany
    {
        return $this->hasMany(Violation::class);
    }

    public function deviceTokens(): HasMany
    {
        return $this->hasMany(DeviceToken::class);
    }

    /**
     * Sales declarations belong to a Lease, not directly to the Tenant, so we
     * reach them through the leases table. Mirrors the portal resource's
     * whereHas('lease', ...) scoping, expressed as a relationship.
     */
    public function salesDeclarations(): HasManyThrough
    {
        return $this->hasManyThrough(TenantSalesDeclaration::class, Lease::class);
    }

    /**
     * Send the mobile-app password reset link. Overrides the default so the
     * link targets the app deep-link rather than a web route.
     */
    public function sendPasswordResetNotification($token): void
    {
        $this->notify(new TenantResetPasswordNotification($token));
    }

    /**
     * Net outstanding AR for this tenant: open invoice balances minus the
     * tenant's unapplied credit-note balances. A tenant carrying a 1000 EGP
     * invoice and a 300 EGP issued credit note owes 700, not 1000.
     */
    /**
     * @param  array<int>|null  $assetIds  Restrict to these properties (pass visibleAssetIds() from
     *                                     an admin surface so a property-restricted operator's view of a shared tenant excludes malls
     *                                     they can't see). null (default) = whole company, for the tenant's own portal/API/statement.
     */
    public function outstandingBalance(?array $assetIds = null): float
    {
        // Sum the COLLECTABLE figure, not `balance`: a partial write-off leaves the balance
        // standing (it is not a settlement channel), so summing it reported the tenant as owing the
        // part the operator had already forgiven — on their statement, on the hub and in the API.
        $invoiceBalance = (float) $this->invoices()
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->sum(DB::raw(Invoice::collectableBalanceSql()));

        // The CreditNote status enum is (draft, issued, applied, void) —
        // a 'partially_applied' state was once contemplated but never
        // shipped. CreditNoteService leaves a partly-applied note in
        // 'issued' (balance > 0); 'issued' alone is the correct filter.
        // See audit M14 F-55 / D-41.
        $creditNoteBalance = (float) $this->creditNotes()
            ->where('status', 'issued')
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->sum('balance');

        return round($invoiceBalance - $creditNoteBalance, 2);
    }

    /**
     * Money the tenant has paid that isn't yet applied to a receivable — the tenant's CREDIT / ON-
     * ACCOUNT balance. It is the sum, over the tenant's RECEIVED payments (captured/reconciled/
     * settled), of each payment's UNALLOCATED remainder (amount − its allocations); that remainder
     * already sits on the books as Unearned Revenue (PaymentJournalizer). Scoped like
     * outstandingBalance(): pass visibleAssetIds() for an admin surface, null for the tenant's own
     * whole-company view. A credit is attributed to the property where the payment was received
     * (its allocated invoices' asset), so a restricted user only sees credit for their properties.
     *
     * @param  array<int>|null  $assetIds
     */
    public function creditBalance(?array $assetIds = null): float
    {
        $payments = $this->payments()->received()
            ->when(
                $assetIds !== null,
                // `invoices`, not `invoices.lease.unit` — an invoice carries its own asset_id, and
                // the old chain matched nothing for a unit-owner invoice. Atomic with
                // `VoidPaymentService`, which computes the ids this is scoped by.
                //
                // GROUPED, deliberately: `(tenant AND received AND …) OR …` binds AND-before-OR, so
                // an ungrouped second branch would escape the tenant scope itself.
                fn ($q) => $q->where(fn ($w) => $w
                    ->whereHas('invoices', fn ($u) => $u->whereIn('invoices.asset_id', $assetIds))
                    // A receipt with NO allocations at all has no invoice to take a property from,
                    // and until 2026-08-24 that made it credit nobody could ever draw: a cleared
                    // SERIES cheque names no invoice (the Egyptian norm), so the money sat in the
                    // bank and in unearned revenue while `ApplyTenantCreditService`'s per-property
                    // cap read 0 and refused every draw — leaving the invoice open for the overdue
                    // sweep and the late-fee run. The property was never unknown: it is on the
                    // cheque, the same fact `Payment::originatingAssetId()` already files the GL
                    // entry under. Only for a receipt with no allocations at all — one allocated
                    // across two properties is a genuinely consolidated receipt and collapsing it
                    // onto one mall would be a different, wrong answer.
                    ->orWhere(fn ($c) => $c
                        ->whereDoesntHave('invoices')
                        ->whereHas('clearedCheque', fn ($ch) => $ch->whereIn('post_dated_cheques.asset_id', $assetIds))
                    )
                ),
            )
            ->with('invoices')
            ->get();

        $credit = 0.0;
        foreach ($payments as $payment) {
            $allocated = (float) $payment->invoices->sum(fn ($i) => (float) $i->pivot->allocated_amount);
            $credit += max(0.0, round((float) $payment->amount - $allocated, 2));
        }

        // Subtract credit already APPLIED to invoices (an on-account draw-down — its own document,
        // soft-deleted rows excluded so a reversal returns the credit here). Scoped by the
        // application's asset (= the settled invoice's property).
        $applied = (float) $this->creditApplications()
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->sum('amount');

        return round($credit - $applied, 2);
    }

    public function creditApplications(): HasMany
    {
        return $this->hasMany(TenantCreditApplication::class);
    }

    /**
     * Delinquent = at least one invoice with a remaining balance is past
     * its due date. Doesn't trust the `status` column alone — that column
     * is only auto-flipped to 'overdue' by Payment hooks, so manually
     * cancelled / orphaned invoices can stay 'issued' indefinitely.
     */
    /**
     * @param  array<int>|null  $assetIds  See outstandingBalance() — scope to visible properties for
     *                                     an admin surface, null (default) for the tenant's own whole-company view.
     */
    public function isDelinquent(?array $assetIds = null): bool
    {
        return $this->invoices()
            ->whereCollectable()
            ->where('due_date', '<', now())
            ->whereIn('status', ['issued', 'partially_paid', 'overdue'])
            ->when($assetIds !== null, fn ($q) => $q->whereIn('asset_id', $assetIds))
            ->exists();
    }

    /**
     * Normalise the Egyptian VAT number to BARE DIGITS on save. The form + importer accept the
     * dashed form (123-456-789) for readability, but ETA's e-invoice expects digits only, and
     * EtaJsonBuilder sends tax_id verbatim — so a dashed value would go on the wire and be rejected.
     * Storing digits-only makes every downstream consumer (ETA, exports) correct by construction.
     */
    public function setTaxIdAttribute($value): void
    {
        $this->attributes['tax_id'] = self::normaliseTaxId($value);
    }

    /**
     * The stored form of an Egyptian VAT number: bare digits, or null.
     *
     * Public and static because a LOOKUP has to normalise the same way a WRITE does. `TenantImporter`
     * identifies a tenant by `tax_id`, and searching for the dashed `123-456-789` against a column
     * that stores `123456789` matches nothing — so a re-import would have created a duplicate of
     * every tenant while looking like it was deduplicating them. One rule, one home.
     */
    public static function normaliseTaxId(?string $value): ?string
    {
        return ($value === null || $value === '')
            ? null
            : preg_replace('/\D+/', '', $value);
    }

    /**
     * The language this retailer reads. The Tenant record is a notifiable in its own right — it is
     * what the mobile app authenticates as and what carries the push device tokens — so it needs its
     * own preference rather than borrowing one of its portal logins'.
     */
    public function preferredLocale(): ?string
    {
        return $this->locale;
    }
}
