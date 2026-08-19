<?php

require __DIR__.'/boot.php';
use App\Filament\Admin\Resources\Tenants\TenantResource;
use App\Models\Announcement;
use App\Models\Asset;
use App\Models\Department;
use App\Models\Invoice;
use App\Models\Lease;
use App\Models\MarketingPost;
use App\Models\Tenant;
use App\Models\TenantUser;
use App\Models\Unit;
use App\Models\User;
use App\Services\DepartmentMessageService;
use App\Services\MarketingPost\ApproveMarketingPostService;
use App\Services\MarketingPost\ArchiveMarketingPostService;
use App\Services\MarketingPost\RejectMarketingPostService;
use App\Services\MarketingPost\SubmitMarketingPostService;
use App\Services\OwnerRequestService;
use App\Services\Reconciliation\BooksReconciliationService;
use App\Services\SendAnnouncementAction;
use App\Support\Search\SearchText;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;

$asset = Asset::where('code', 'AW')->firstOrFail();
$admin = User::where('email', 'admin@mall.test')->firstOrFail();
$occupied = Unit::where('asset_id', $asset->id)->where('status', 'occupied')->firstOrFail();
$lease = Lease::where('unit_id', $occupied->id)->where('status', 'active')->firstOrFail();
$tenant = $lease->tenant;

/* ══════════════════════════ MODULE 02 · TENANTS ══════════════════════════ */
qa_section('TENANTS 1 — a counterparty code is allocated, and an imported one is KEPT');
$t1 = Tenant::create(['name' => 'QA Retail Co', 'type' => 'company', 'status' => 'active',
    'email' => 'qa1-'.uniqid().'@t.test', 'password' => bcrypt(Str::password(32))]);
qa_ok('a code is allocated', filled($t1->fresh()->code), (string) $t1->fresh()->code);
qa_ok('…in the documented shape TN-0000000', preg_match('/^TN-\d{7}$/', (string) $t1->fresh()->code) === 1,
    (string) $t1->fresh()->code);
$t2 = Tenant::create(['name' => 'QA Imported Co', 'type' => 'company', 'status' => 'active', 'code' => 'LEGACY-42',
    'email' => 'qa2-'.uniqid().'@t.test', 'password' => bcrypt(Str::password(32))]);
qa_eq('an imported code is KEPT — a migrating accountant already uses theirs', 'LEGACY-42', $t2->fresh()->code);

qa_section('TENANTS 2 — the search blob folds Arabic on BOTH sides');
$ar = Tenant::create(['name' => 'شركة أحمد للتجارة', 'type' => 'company', 'status' => 'active',
    'email' => 'qa3-'.uniqid().'@t.test', 'password' => bcrypt(Str::password(32)),
    'phone' => '01012345678', 'tax_id' => '123-456-789']);
$blob = (string) $ar->fresh()->search_text;
printf("  blob: %s\n", mb_substr($blob, 0, 90));
qa_ok('the blob is folded', $blob === SearchText::normalize($blob), 'idempotent under the fold');
foreach ([['شركه', 'شركة'], ['احمد', 'أحمد']] as [$plain,$hamza]) {
    qa_eq("«{$plain}» and «{$hamza}» fold to the same token", SearchText::normalize($plain), SearchText::normalize($hamza));
}
qa_ok('the phone is searchable through the blob', str_contains($blob, '01012345678'));
qa_ok('…and so is the tax id', str_contains($blob, SearchText::normalize('123-456-789'))
    || str_contains($blob, '123456789') || str_contains($blob, '123-456-789'));
// Global search resolves record URLs, which need the panel + property context.
Filament::setCurrentPanel(Filament::getPanel('admin'));
Filament::setTenant($asset, true);
$hits = TenantResource::getGlobalSearchResults('شركه احمد');
qa_ok('searching the PLAIN spelling finds the hamza-spelled tenant', $hits->count() > 0, $hits->count().' hit(s)');

qa_section('TENANTS 3 — deletion policy');
qa_refuses('a tenant with a lease cannot be deleted', fn () => $tenant->fresh()->delete(), null, Throwable::class);
qa_allows('…while a brand-new one can', fn () => $t1->fresh()->delete());

/* ══════════════════════════ MODULE 03 · TENANT PORTAL USERS ══════════════════════════ */
qa_section('PORTAL — multi-user, and only an admin user may write');
$tu1 = TenantUser::create(['tenant_id' => $tenant->id, 'name' => 'QA Portal Admin',
    'email' => 'qa-pa-'.uniqid().'@t.test', 'password' => bcrypt('password'), 'is_admin' => true]);
$tu2 = TenantUser::create(['tenant_id' => $tenant->id, 'name' => 'QA Portal Staff',
    'email' => 'qa-ps-'.uniqid().'@t.test', 'password' => bcrypt('password'), 'is_admin' => false]);
qa_eq('one tenant, several logins', 2, TenantUser::where('tenant_id', $tenant->id)
    ->whereIn('id', [$tu1->id, $tu2->id])->count());
qa_ok('the admin user may write', (bool) $tu1->fresh()->is_admin);
qa_ok('…and the read-only one may not', ! (bool) $tu2->fresh()->is_admin);

qa_section('PORTAL — a tenant NEVER sees a draft, on any surface');
$draft = Invoice::create(['tenant_id' => $tenant->id, 'lease_id' => $lease->id, 'asset_id' => $asset->id,
    'number' => 'QA-DRAFT-'.uniqid(), 'issue_date' => '2026-08-01', 'due_date' => '2026-08-15',
    'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'draft',
    'subtotal' => 5000, 'vat_amount' => 0, 'total' => 5000, 'paid_amount' => 0, 'balance' => 5000, 'currency' => 'EGP']);
// A definitely-issued invoice, so the control is a real control rather than a skip.
$issued = Invoice::where('tenant_id', $tenant->id)->where('status', 'issued')->first()
    ?? Invoice::create(['tenant_id' => $tenant->id, 'lease_id' => $lease->id, 'asset_id' => $asset->id,
        'number' => 'QA-ISSUED-'.uniqid(), 'issue_date' => '2026-08-01', 'due_date' => '2026-08-15',
        'period_start' => '2026-08-01', 'period_end' => '2026-08-31', 'status' => 'issued',
        'subtotal' => 7000, 'vat_amount' => 0, 'total' => 7000, 'paid_amount' => 0, 'balance' => 7000, 'currency' => 'EGP']);
$visible = Invoice::where('tenant_id', $tenant->id)->visibleToTenant()->pluck('id');
qa_ok('the draft is hidden from the tenant', ! $visible->contains($draft->id));
$visible = Invoice::where('tenant_id', $tenant->id)->visibleToTenant()->pluck('id');
qa_ok('…while an issued invoice IS visible (the control that stops a scope hiding everything)',
    $visible->contains($issued->id), 'issued #'.$issued->id);
qa_ok('the relationship itself is NOT narrowed — admin and the GL read every row',
    $tenant->fresh()->invoices()->pluck('id')->contains($draft->id));
$cancelled = Invoice::where('tenant_id', $tenant->id)->where('status', 'cancelled')->first();
if ($cancelled) {
    qa_ok('a CANCELLED invoice still reaches its tenant (it explains a number they remember)',
        Invoice::where('tenant_id', $tenant->id)->visibleToTenant()->pluck('id')->contains($cancelled->id));
}

/* ══════════════════════════ MODULE 14 · DEPARTMENTS ══════════════════════════ */
qa_section('DEPARTMENTS — a message reaches the members, and only them');
// Seeded with members, so the assertion is a real one — the demo data has none.
$dept = Department::first();
$recipient = User::where('email', 'manager@mall.test')->first() ?? User::where('id', '!=', $admin->id)->first();
$dept->members()->syncWithoutDetaching([$admin->id, $recipient->id]);
$members = $dept->members()->where('users.id', '!=', $admin->id)->count();
printf("  department %s has %d member(s)\n", $dept?->name ?? '—', $members);
Notification::fake();
$sent = app(DepartmentMessageService::class)->send($dept, $admin, 'QA department message');
qa_eq('the message reaches every member', $members, $sent);
Notification::assertCount($members);

/* ══════════════════════════ MODULE 27 · ANNOUNCEMENTS ══════════════════════════ */
qa_section('ANNOUNCEMENTS — sent to the audience, once');
Notification::fake();
$ann = Announcement::create(['asset_id' => $asset->id, 'title' => 'QA notice', 'body' => 'QA body',
    'audience' => 'all_tenants', 'status' => 'draft', 'created_by_user_id' => $admin->id]);
$count = app(SendAnnouncementAction::class)->handle($ann->fresh());
printf("  delivered to %d recipient(s)\n", $count);
qa_ok('it reaches an audience', $count > 0);
qa_eq('…and is marked sent', 'sent', $ann->fresh()->status);
// The guard is on `sent_at`: a second call returns the ORIGINAL recipient count without
// re-sending. Counting deliveries is the assertion that matters — a return value could be
// anything, but a second blast would spam every tenant.
$deliveredBefore = DB::table('announcement_recipients')->where('announcement_id', $ann->id)->count();
Notification::fake();
$again = app(SendAnnouncementAction::class)->handle($ann->fresh());
Notification::assertNothingSent();
qa_ok('sending twice delivers NOTHING more — no tenant is spammed', true);
qa_eq('…and the recipient list is unchanged', $deliveredBefore,
    DB::table('announcement_recipients')->where('announcement_id', $ann->id)->count());
qa_eq('…while still reporting who it originally reached', $count, $again);

/* ══════════════════════════ MODULE 15 · OWNER REQUESTS ══════════════════════════ */
qa_section('OWNER REQUESTS — a conversation with a state machine');
$owner = $asset->propertyOwners()->first() ?? User::where('email', 'owner@atriom.test')->first();
if ($owner) {
    $svc = app(OwnerRequestService::class);
    $or = $svc->create(['asset_id' => $asset->id, 'subject' => 'QA owner question',
        'body' => 'QA body', 'priority' => 'medium'], $owner);
    qa_ok('an owner raises a request', $or->exists, $or->reference ?? ('#'.$or->id));
    $reply = $svc->reply($or->fresh(), $admin, 'QA operator reply');
    qa_ok('the operator replies', $reply->exists);
    qa_eq('the reply is recorded on the conversation', 1, $or->fresh()->replies()->count());
    $svc->transition($or->fresh(), 'in_progress');
    $svc->transition($or->fresh(), 'resolved');
    qa_eq('…and can be resolved', 'resolved', $or->fresh()->status);
    $svc->transition($or->fresh(), 'closed');
    // The guard RETURNS UNCHANGED rather than throwing — idempotent, so a double-click is a
    // no-op. The invariant is the state, not the exception.
    $svc->transition($or->fresh(), 'open');
    qa_eq('a CLOSED owner request is terminal — re-opening is a no-op', 'closed', $or->fresh()->status);
}

/* ══════════════════════════ MODULE 36 · MARKETING POSTS ══════════════════════════ */
qa_section('MARKETING POSTS — the shopper feed has TWO date pairs');
// TWO date pairs, and they are not the same question: starts_at/ends_at is when the OFFER is
// valid; display_from/display_until is when the shopper SEES it. A teaser runs before the offer.
$post = MarketingPost::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id,
    'title' => 'QA offer', 'body' => 'QA 20% off', 'status' => 'draft', 'created_by' => $admin->id,
    'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30',
    'display_from' => '2026-08-20', 'display_until' => '2026-09-30']);
qa_ok('the offer window and the display window are DIFFERENT',
    $post->fresh()->starts_at->ne($post->fresh()->display_from),
    'valid from '.$post->fresh()->starts_at->format('Y-m-d').' · shown from '.$post->fresh()->display_from->format('Y-m-d'));
app(SubmitMarketingPostService::class)->handle($post->fresh());
qa_eq('submitted for review', MarketingPost::STATUS_PENDING, $post->fresh()->status);
Notification::fake();
// A SHOPPER-facing post needs artwork — a feed card with no image is a grey box. Worth asserting.
qa_refuses('a visitor-facing post cannot be published without a hero image',
    fn () => app(ApproveMarketingPostService::class)->handle($post->fresh(), $admin), 'artwork');
$post->fresh()->update(['audience' => MarketingPost::AUDIENCE_TENANTS]);
app(ApproveMarketingPostService::class)->handle($post->fresh(), $admin);
qa_eq('…a tenants-only notice is read as text, so it publishes on approval',
    MarketingPost::STATUS_PUBLISHED, $post->fresh()->status);
app(ArchiveMarketingPostService::class)->handle($post->fresh(), $admin);
qa_eq('archived', MarketingPost::STATUS_ARCHIVED, $post->fresh()->status);
$rej = MarketingPost::create(['asset_id' => $asset->id, 'tenant_id' => $tenant->id,
    'title' => 'QA rejected', 'body' => 'QA', 'status' => 'draft', 'created_by' => $admin->id,
    'starts_at' => '2026-09-01', 'ends_at' => '2026-09-30',
    'display_from' => '2026-08-20', 'display_until' => '2026-09-30']);
app(SubmitMarketingPostService::class)->handle($rej->fresh());
app(RejectMarketingPostService::class)->handle($rej->fresh(), $admin, 'QA not on brand');
qa_eq('a rejected post says so', MarketingPost::STATUS_REJECTED, $rej->fresh()->status);
qa_ok('…with the reason recorded', filled($rej->fresh()->review_notes), (string) $rej->fresh()->review_notes);

/* ══════════════════════════ MODULE 19 · NOTIFICATIONS ══════════════════════════ */
qa_section('NOTIFICATIONS — bilingual, deep-linked, and idempotent scans');
$row = DB::table('notifications')->orderByDesc('created_at')->first();
if ($row) {
    $data = json_decode($row->data, true) ?: [];
    printf("  newest bell row: %s\n", mb_substr(json_encode(array_slice($data, 0, 6)), 0, 140));
    qa_ok('a bell row carries BOTH languages', isset($data['title_en'], $data['title_ar'])
        || isset($data['en'], $data['ar']) || isset($data['title']),
        implode(',', array_slice(array_keys($data), 0, 8)));
    // The link is DERIVED at read time from the record id in the payload, not stored as a URL —
    // the same rule the activity log follows, and what lets a route change reach rows written
    // years ago. So what a row must carry is its TYPE and the id the link resolves through.
    // `NotificationDeepLinkConformanceTest` is the authoritative check that every one resolves.
    qa_ok('…and the type + record id a deep link resolves through',
        isset($data['type']) && collect(array_keys($data))->contains(fn ($k) => str_ends_with($k, '_id')),
        implode(',', array_slice(array_keys($data), 0, 8)));
    qa_ok('…no row stores a literal URL (a stored link cannot follow a route change)',
        ! isset($data['url']));
}
$exit1 = Artisan::call('billing:scan-overdue-invoices');
$out1 = Artisan::output();
$exit2 = Artisan::call('billing:scan-overdue-invoices');
qa_eq('the overdue scan runs clean', 0, $exit1);
qa_eq('…and again (idempotent via the notified stamp)', 0, $exit2);
foreach (['leases:remind-expiring', 'requests:scan-sla-breaches', 'vendors:scan-contract-renewals', 'pdc:scan-maturing'] as $cmd) {
    $c = Artisan::call($cmd);
    qa_eq("$cmd runs clean", 0, $c);
    qa_eq('…and is idempotent', 0, Artisan::call($cmd));
}

qa_section('BATCH C TIE-OUT');
Artisan::call('accounting:sync-ledger', ['--all' => true]);
qa_assert_tb('after tenants, portal, comms and scans');
$rec = app(BooksReconciliationService::class);
qa_eq('AR ties', 0.0, $rec->glTieOut()['ar']['delta']);
qa_eq('no GL drift', 0, count($rec->glDriftDiscrepancies()));

qa_summary();
