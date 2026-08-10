<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marketing posts — the shopper-facing content feed: "Defacto, 20% off until Thursday".
 *
 * **Why this is not an `Announcement`.** Module 27 broadcasts operational notices to the mall's
 * TENANTS: text only, immutable, composing IS sending, and it reaches whoever held an active lease
 * at that instant. A shopper offer is the opposite on every axis — it carries artwork, it is
 * reviewed before anyone sees it, it is edited while it runs, it has a validity window that outlives
 * the moment it was written, and its audience is the public. Bolting a nullable image and a date
 * range onto Announcement would have turned an audit record ("who told the tenants what, when")
 * into a CMS, and the two have opposite requirements around mutability.
 *
 * **The two date pairs are deliberate, and this is the field the shape exists for.** Every mature
 * offer system (schema.org `Offer.validFrom`/`validThrough`, Google Merchant's promotion display
 * dates) separates *when the discount is honoured* from *when the card is on screen*, because they
 * are genuinely different: a Black Friday offer is teased for a week and valid for a day, and a
 * Ramadan campaign is published the moment the artwork is approved but valid only from the 1st.
 * Collapsing them into one pair forces the operator to choose which of the two truths to lie about
 * — and the lie lands on the shopper, who is told an offer is over when it has not started.
 *
 *   starts_at / ends_at         → VALIDITY. What the card says ("valid until 31 Aug"). The promise.
 *   display_from / display_until → VISIBILITY. When it is in the feed at all. Nullable: null means
 *                                  "follow the validity window", which is the common case.
 *
 * **`tenant_id` is nullable on purpose.** A post belongs either to a store (the usual case — the
 * card shows that store's logo and where to find it) or to the mall itself ("late-night shopping
 * every Thursday in Ramadan"), which has no store behind it. Making it required would have forced
 * a fake tenant row for mall-wide content.
 *
 * **Approval is a column, not a convention.** Tenants submit their own offers (Mallcomm, Placewise
 * and Mall Maverick all work this way — it is the only model that scales past a handful of stores),
 * so anything a retailer typed reaches the public only after an operator approved it. `status`
 * carries that, and `review_notes` carries the WHY of a rejection: a tenant told only "rejected"
 * resubmits the same thing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_posts', function (Blueprint $table) {
            $table->id();

            // The mall this post runs in. Property-owned — an offer is never portfolio-wide,
            // because a shopper is standing in one building.
            $table->foreignId('asset_id')->constrained('assets')->cascadeOnDelete();

            // The store behind the offer. NULL = a mall-wide post (see the docblock).
            // restrictOnDelete would be wrong (Tenant is WHEN_UNUSED-deletable while unused) and
            // cascade would silently erase published content, so the post survives and goes
            // mall-wide — visible, and repairable by an operator.
            $table->foreignId('tenant_id')->nullable()->constrained('tenants')->nullOnDelete();

            // Strings, not DB enums — the house rule (validated in the form + model constants).
            // `offer` | `event` | `news`
            $table->string('type')->default('offer');
            // `draft` | `pending` | `published` | `rejected` | `archived`
            $table->string('status')->default('draft');
            // `visitors` | `tenants` | `both` — who the feed serves it to. A staff-only discount
            // ("20% off for mall employees") is a real thing and must never reach the public API.
            $table->string('audience')->default('visitors');

            // ---- Shopper-visible copy. Arabic is a first-class sibling column, matching
            // ledger_accounts.name_ar / equipment.name_ar — Egyptian shoppers are the audience,
            // and an English-only mall app is a broken mall app.
            $table->string('title');
            $table->string('title_ar')->nullable();
            $table->string('summary', 500)->nullable();
            $table->string('summary_ar', 500)->nullable();
            $table->text('body')->nullable();
            $table->text('body_ar')->nullable();
            // The small print: "excludes sale items", "one per customer". Kept apart from `body`
            // so the app can render it in the fine-print slot every offer card has.
            $table->text('terms')->nullable();
            $table->text('terms_ar')->nullable();

            // The badge on the card — "20% OFF", "خصم ٢٠٪", "Buy 1 Get 1". Deliberately a free-text
            // LABEL and not a computed discount: mall offers are not priceable (they are not tied to
            // a SKU catalogue the mall owns), and a numeric percent column would invite a total that
            // no system here can honour. Google Merchant makes the same split.
            $table->string('discount_label', 60)->nullable();
            $table->string('discount_label_ar', 60)->nullable();

            // ---- The two windows. See the docblock — this is the point of the table.
            $table->dateTime('starts_at')->nullable();     // validity: schema.org validFrom
            $table->dateTime('ends_at')->nullable();       // validity: schema.org validThrough
            $table->dateTime('display_from')->nullable();  // visibility; null → follow starts_at
            $table->dateTime('display_until')->nullable(); // visibility; null → follow ends_at

            // ---- The carousel. `is_featured` is eligibility, `priority` is ordering within it
            // (higher first). Two columns rather than one nullable rank, so un-featuring a post
            // does not lose the ordering the marketing team already agreed.
            $table->boolean('is_featured')->default(false);
            $table->integer('priority')->default(0);

            // Optional call to action — "Book a table", "See the collection".
            $table->string('cta_label', 60)->nullable();
            $table->string('cta_label_ar', 60)->nullable();
            $table->string('cta_url', 500)->nullable();

            // ---- Authorship & review.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('submitted_by_tenant_user_id')->nullable()->constrained('tenant_users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('reviewed_at')->nullable();
            // Why it was rejected. A tenant told only "rejected" resubmits the same thing.
            $table->string('review_notes', 1000)->nullable();
            $table->dateTime('published_at')->nullable();

            // ---- Engagement. Counters, not an event log: the question a mall marketer asks is
            // "which campaign worked", and a per-impression table would cost far more than that
            // answer is worth here. NOT NULL with a default so an increment never meets null.
            $table->unsignedBigInteger('view_count')->default(0);
            $table->unsignedBigInteger('click_count')->default(0);

            // Fold-normalized search blob (App\Models\Concerns\HasSearchText) — an operator hunts
            // for "رمضان" or "Defacto", and both sides go through App\Support\Search\SearchText.
            $table->text('search_text')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // The public feed's query: one property, published, inside its display window.
            $table->index(['asset_id', 'status', 'display_from']);
            $table->index(['asset_id', 'type', 'status']);
            // The carousel's query, which orders before it filters.
            $table->index(['asset_id', 'is_featured', 'priority']);
            $table->index('tenant_id');
        });

        // The money side. A marketing spend can now name the campaign it paid for, which is the
        // join no competitor has: Placewise and Mallcomm hold the content, Yardi holds the ledger,
        // and reconciling them is a spreadsheet. Many spends → one post (artwork, printing and the
        // influencer are three lines against one Ramadan campaign), so the FK lives here.
        Schema::table('marketing_spends', function (Blueprint $table) {
            $table->foreignId('marketing_post_id')->nullable()->after('marketing_budget_id')
                ->constrained('marketing_posts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('marketing_spends', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marketing_post_id');
        });

        Schema::dropIfExists('marketing_posts');
    }
};
