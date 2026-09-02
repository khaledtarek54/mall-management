<?php

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Services\SendAnnouncementAction;
use Illuminate\Support\Facades\Notification;

/**
 * A NOTICE WHOSE WINDOW HAS ALREADY SHUT SENDS EVERY TENANT TO A 404.
 *
 * `expires_at` was accepted unvalidated, so a notice could be broadcast with an end date already in
 * the past — or before its own `publish_at`. The blast still goes out: every tenant gets the push
 * and the bell, `announcement_recipients` records who was reached, and then the portal's own scope
 * (`whereNull('expires_at')->orWhere('expires_at', '>=', now())`) excludes it. The deep link every
 * one of them taps lands on nothing.
 *
 * **And there is no way back.** `isEditable()` is false the moment a notice is sent — correctly,
 * because it is evidence: tenants hold a notification quoting its text. So the only repair is
 * composing a SECOND notice to explain the first, which is a worse thing to have to send than the
 * original.
 *
 * Guarded in the SERVICE rather than only on the form, and the reason is the scheduled sweep: an
 * `expires_at` that was in the future when the notice was scheduled can be in the past by the time
 * the sweep reaches it, which no form rule can see.
 */
beforeEach(function () {
    Notification::fake();

    $this->asset = makeAsset();
    $this->tenant = makeTenant();
    makeLease(makeUnit($this->asset), $this->tenant, ['status' => 'active']);
});

function noticeExpiring(?string $expiresAt): Announcement
{
    return Announcement::create([
        'asset_id' => test()->asset->id,
        'title' => 'Loading bay closed',
        'body' => 'Friday, all day.',
        'expires_at' => $expiresAt,
    ]);
}

it('refuses to broadcast a notice that has already expired', function () {
    $notice = noticeExpiring(now()->subDay()->toDateTimeString());

    expect(fn () => app(SendAnnouncementAction::class)->handle($notice))
        ->toThrow(DomainException::class);

    // Nothing went out, and nothing was recorded as having gone out — a half-sent notice is worse
    // than a refused one, because `announcement_recipients` is what says who was reached.
    expect($notice->fresh()->sent_at)->toBeNull()
        ->and(AnnouncementRecipient::where('announcement_id', $notice->id)->count())->toBe(0);

    Notification::assertNothingSent();
});

it('still broadcasts one whose window is open', function () {
    // The control. A guard that refused everything would satisfy the refusal above and silently
    // stop the whole feature.
    $notice = noticeExpiring(now()->addWeek()->toDateTimeString());

    expect(app(SendAnnouncementAction::class)->handle($notice))->toBe(1);

    expect($notice->fresh()->sent_at)->not->toBeNull()
        ->and(AnnouncementRecipient::where('announcement_id', $notice->id)->count())->toBe(1);
});

it('still broadcasts one with no end date at all', function () {
    // Null means "no end", which is what an operator means by a standing notice — and it is the
    // ordinary case, so a guard that tripped on it would break almost every broadcast.
    $notice = noticeExpiring(null);

    expect(app(SendAnnouncementAction::class)->handle($notice))->toBe(1)
        ->and($notice->fresh()->sent_at)->not->toBeNull();
});

it('catches a SCHEDULED notice whose window shut while it waited', function () {
    // The case no form rule can see, and the reason the guard is in the service: the operator
    // scheduled it for Tuesday with an end on Wednesday, nobody ran the sweep, and by the time it
    // fires the window is behind us.
    $notice = Announcement::create([
        'asset_id' => $this->asset->id,
        'title' => 'Water shut-off',
        'body' => 'Tuesday 09:00–13:00.',
        'status' => Announcement::STATUS_SCHEDULED,
        'publish_at' => now()->subDays(3)->toDateTimeString(),
        'expires_at' => now()->subDays(2)->toDateTimeString(),
    ]);

    expect(fn () => app(SendAnnouncementAction::class)->handle($notice))
        ->toThrow(DomainException::class);

    expect($notice->fresh()->sent_at)->toBeNull();
});

it('leaves an already-sent notice alone, whatever its window says', function () {
    // The idempotency guard runs first and must keep running first: a notice that HAS been sent and
    // has since expired is the ordinary end state of every notice ever, and re-entering the send
    // path for it must return the recorded count rather than throw.
    $notice = noticeExpiring(now()->addWeek()->toDateTimeString());
    app(SendAnnouncementAction::class)->handle($notice);

    $notice->forceFill(['expires_at' => now()->subDay()])->save();

    expect(app(SendAnnouncementAction::class)->handle($notice->fresh()))->toBe(1);
});

it('lets ONE poison notice cost only its own delivery', function () {
    // **The refusal nearly bricked the whole sweep.** The command's loop had no catch, so one
    // expired notice threw, every notice behind it in the same run went unsent, and the bad row
    // stayed `scheduled` with a past `publish_at` — which `dueToSend()` returns on EVERY run,
    // ordered by `publish_at`, so it came first every time. The command runs every fifteen minutes:
    // all scheduled announcements would have stopped, permanently, with nothing alerting.
    $shut = Announcement::create([
        'asset_id' => $this->asset->id,
        'title' => 'Water shut-off', 'body' => 'Tuesday.',
        'status' => Announcement::STATUS_SCHEDULED,
        'publish_at' => now()->subDays(3)->toDateTimeString(),
        'expires_at' => now()->subDays(2)->toDateTimeString(),
    ]);

    $healthy = Announcement::create([
        'asset_id' => $this->asset->id,
        'title' => 'Ramadan hours', 'body' => 'From next week.',
        'status' => Announcement::STATUS_SCHEDULED,
        // LATER than the poison row, so the sweep's `orderBy('publish_at')` reaches it second —
        // which is exactly the ordering that made one bad row starve everything behind it.
        'publish_at' => now()->subDay()->toDateTimeString(),
        'expires_at' => now()->addMonths(3)->toDateTimeString(),
    ]);

    $this->artisan('announcements:send-scheduled')->assertFailed();

    expect($healthy->fresh()->sent_at)->not->toBeNull('the healthy notice behind the poison row was starved')
        ->and($shut->fresh()->sent_at)->toBeNull();
});

it('tells the operator on the CREATE page, not a failed_jobs row', function () {
    // The broadcast goes off the request thread and the job is `tries = 1` on the database queue,
    // so a refusal inside it becomes a failed job the operator never sees: the record is created,
    // the success toast shows, `sent_at` stays null, and nothing on screen says it was refused. The
    // window check is cheap and needs no tenants, so it is asked on the request.
    $page = file_get_contents(base_path('app/Filament/Admin/Resources/Announcements/Pages/CreateAnnouncement.php'));

    expect($page)->toContain('assertSendable()')
        ->and($page)->toContain('Notification::make()->danger()');
});

it('asks ONE rule from all three callers', function () {
    // The service, the create page and the sweep each need the same answer and only one of them has
    // a form, so the rule is on the model. Three copies of a refusal is how they drift.
    // No message argument: `toContain()`'s second parameter is ANOTHER NEEDLE, not a description —
    // the trap this project already records for Pest matchers, and it turns a passing assertion
    // into a failing one that reads like a bug in the code under test.
    $restating = [];

    foreach ([
        'app/Services/SendAnnouncementAction.php',
        'app/Filament/Admin/Resources/Announcements/Pages/CreateAnnouncement.php',
    ] as $file) {
        if (! str_contains(file_get_contents(base_path($file)), 'assertSendable()')) {
            $restating[] = $file;
        }
    }

    expect($restating)->toBe([], 'These restate the window rule instead of asking the model: '
        .implode(', ', $restating));
});
