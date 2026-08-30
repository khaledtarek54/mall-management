<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| atriom:notify-status → Discord
|--------------------------------------------------------------------------
| The design property under test is NOT "does it post" — it is "does it stay QUIET". A correct
| staging box is permanently degraded (two_factor, demo_accounts and backup_capability are all
| expected red on posture A), so a command that posted whenever health was not green would fire
| every fifteen minutes for ever about nothing. STAGING.md already names the consequence for
| uptime monitors: a monitor that is always red is one nobody reads, including the production one
| beside it.
*/

use App\Console\Commands\NotifyStatusCommand;
use App\Support\Discord;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    File::delete(NotifyStatusCommand::statePath());
    config([
        'discord.webhook_url' => 'https://discord.test/api/webhooks/1/abc',
        'discord.username' => 'Atriom testing',
    ]);
});

afterEach(fn () => File::delete(NotifyStatusCommand::statePath()));

it('does nothing at all when no webhook is configured', function () {
    config(['discord.webhook_url' => null]);
    Http::fake();

    expect(Discord::enabled())->toBeFalse();

    $this->artisan('atriom:notify-status')->assertSuccessful();

    Http::assertNothingSent();
    expect(File::exists(NotifyStatusCommand::statePath()))->toBeFalse();
});

it('posts once on a box it has never reported on, then goes quiet', function () {
    Http::fake(['*' => Http::response('', 204)]);

    // First run: the operator learns the alerting works, rather than inferring it from a
    // silence indistinguishable from a broken webhook.
    $this->artisan('atriom:notify-status')->assertSuccessful();
    Http::assertSentCount(1);

    // Second run, nothing changed: silence.
    $this->artisan('atriom:notify-status')->assertSuccessful();
    Http::assertSentCount(1);

    // Third, fourth… still silence. This is the property that makes it readable.
    $this->artisan('atriom:notify-status')->assertSuccessful();
    $this->artisan('atriom:notify-status')->assertSuccessful();
    Http::assertSentCount(1);
});

it('posts again when the set of failing checks changes', function () {
    Http::fake(['*' => Http::response('', 204)]);

    $this->artisan('atriom:notify-status')->assertSuccessful();
    Http::assertSentCount(1);

    // Something else broke since the last report.
    File::put(NotifyStatusCommand::statePath(), json_encode(['a_check_that_since_recovered']));

    $this->artisan('atriom:notify-status')->assertSuccessful();
    Http::assertSentCount(2);
});

it('names both what broke and what recovered', function () {
    $bodies = [];
    Http::fake(function ($request) use (&$bodies) {
        $bodies[] = $request->data();

        return Http::response('', 204);
    });

    // Pretend we last reported a check that is fine now, so this run has a recovery in it.
    File::put(NotifyStatusCommand::statePath(), json_encode(['invented_check']));

    $this->artisan('atriom:notify-status')->assertSuccessful();

    $description = $bodies[0]['embeds'][0]['description'] ?? '';

    expect($description)
        ->toContain('invented_check')
        ->toContain('recovered');
});

it('does NOT advance its state when Discord refuses the post', function () {
    // Otherwise an undelivered change is remembered as delivered and the operator is never told
    // about it — the failure would be permanent and completely silent.
    //
    // A SEQUENCE, not two Http::fake() calls: fake() APPENDS stubs rather than replacing them and
    // the first match wins, so a second fake('*') never takes effect and the run under test would
    // still see the 500 it was given first.
    Http::fakeSequence()->push('nope', 500)->push('', 204);

    $this->artisan('atriom:notify-status')->assertFailed();

    expect(File::exists(NotifyStatusCommand::statePath()))->toBeFalse();

    // Discord is back: the change it could not deliver is still reported, not skipped.
    $this->artisan('atriom:notify-status')->assertSuccessful();

    Http::assertSentCount(2);
    expect(File::exists(NotifyStatusCommand::statePath()))->toBeTrue();
});

it('says which box it came from', function () {
    // An alert that does not name its environment is read as production.
    $bodies = [];
    Http::fake(function ($request) use (&$bodies) {
        $bodies[] = $request->data();

        return Http::response('', 204);
    });

    $this->artisan('atriom:notify-status')->assertSuccessful();

    expect($bodies[0]['username'] ?? '')->toBe('Atriom testing');
});
