<?php

/*
|--------------------------------------------------------------------------
| A notification's record does not depend on its transport (SW-252)
|--------------------------------------------------------------------------
| Found on the staging soak, 2026-09-11, by reading the calendar against the box: the 10th's
| `sales:scan-missing-declarations` should have reminded two tenants about August, and NOTHING
| happened — no bell row, no activity, no log line, exit SUCCESS. The mail transport was answering
| `403 Forbidden` that morning, and `SalesDeclarationReminderNotification::via()` lists `mail`
| before `database`. Laravel sends an inline notification's channels sequentially with no catch
| between them, so the throw on the tenant's mail erased the tenant's `database` row — the reminder
| the mobile app shows AND the stamp the scan de-duplicates on — and never reached the portal login
| behind it. Next run: 10 October, which scans September. August was gone for good.
|
| Nineteen of the app's thirty-five inline notifications have that `via()` order. The fix is ONE
| seam — the mail channel, bound in the container the way `BellChannel` is — with limits that are
| the whole design and are each a tooth here: only what an ENVIRONMENT can throw is caught (a fault
| inside `toMail()` stays loud); a QUEUED notification's mail is left to the queue, because its job
| already isolates the channels and carries the retries and the `failed_jobs` row that
| `atriom:health` watches; a notification with no `database` channel has no record to protect and
| throws as before; and a caller may INSIST (`requiringDelivery()`), which the invoice re-send does.
|
| The review of the first draft found the caught family was exactly the one the box had already
| shown: MailerSend re-wraps only its HTTP exception, so a 422 (the trial plan's recipient cap) and
| a DNS failure walked straight past it — and it found the invoice re-send now told the operator
| "sent" over a message that never left. Both are teeth below.
|
| The transport is driven for real: `Mail::extend()` registers a Symfony transport that throws the
| same exception class MailerSend threw, so every assertion below runs through the actual
| `MailChannel::send()` path rather than a mocked facade.
*/

use App\Models\Lease;
use App\Models\Tenant;
use App\Notifications\Channels\BestEffortMailChannel;
use App\Services\SendInvoiceToTenantService;
use Database\Seeders\AccountMappingSeeder;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesPermissionsSeeder;
use GuzzleHttp\Psr7\Request;
use Http\Client\Exception\NetworkException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use MailerSend\Exceptions\MailerSendException;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Exception\RfcComplianceException;

/** A queued twin of the inline probe — a NAMED class, because the sync queue serialises the job. */
final class Sw252QueuedProbeNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function via(object $notifiable): array
    {
        return ['database', 'mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject('SW-252 queued probe')->line('probe');
    }

    public function toArray(object $notifiable): array
    {
        return ['type' => 'sw252_queued_probe'];
    }
}

beforeEach(function () {
    $this->seed(RolesPermissionsSeeder::class);
    ensureAllPropertiesAsset();
    $this->asset = makeAsset();
});

/**
 * Route ALL mail through a transport whose every send throws — by default what MailerSend threw
 * on the box, or any other shape an environment can produce.
 */
function sw252TransportThatFails(?Throwable $with = null): void
{
    $with ??= new TransportException('[url] /v1/email [http method] POST [status code] 403 [reason phrase] Forbidden');

    Mail::extend('sw252-failing', fn () => new class($with) extends AbstractTransport
    {
        public function __construct(private readonly Throwable $with)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            throw $this->with;
        }

        public function __toString(): string
        {
            return 'sw252-failing';
        }
    });

    config(['mail.mailers.sw252-failing' => ['transport' => 'sw252-failing'], 'mail.default' => 'sw252-failing']);
}

/** …or through one that counts what it was handed — the control's transport. */
function sw252TransportThatCounts(stdClass $tally): void
{
    Mail::extend('sw252-counting', fn () => new class($tally) extends AbstractTransport
    {
        public function __construct(private readonly stdClass $tally)
        {
            parent::__construct();
        }

        protected function doSend(SentMessage $message): void
        {
            $this->tally->sent++;
        }

        public function __toString(): string
        {
            return 'sw252-counting';
        }
    });

    config(['mail.mailers.sw252-counting' => ['transport' => 'sw252-counting'], 'mail.default' => 'sw252-counting']);
}

/** The shape nineteen inline notifications share: mail FIRST, then the in-app record. */
function sw252InlineProbe(): Notification
{
    return new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail', 'database'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('SW-252 probe')->line('probe');
        }

        public function toArray(object $notifiable): array
        {
            return ['type' => 'sw252_probe'];
        }
    };
}

it('writes the in-app record even though the mail transport failed, and does not throw at the caller', function () {
    sw252TransportThatFails();
    $user = makeUser('manager', [$this->asset->id]);

    $ops = captureOpsLog(fn () => $user->notify(sw252InlineProbe()));

    // The record survived the transport — this is the row the bell, the mobile app and every
    // scan's idempotency stamp read.
    expect($user->notifications()->where('data->type', 'sw252_probe')->exists())->toBeTrue();

    // …and the miss is on the ops log, where the daily check counts it: WARNING, naming the
    // notification and the recipient, never the address.
    $miss = collect($ops)->firstWhere('message', 'notification.delivery_failed');
    expect($miss)->not->toBeNull()
        ->and($miss['level'])->toBe('warning')
        ->and($miss['context']['channel'])->toBe('mail')
        ->and($miss['context']['notifiable'])->toBe('User#'.$user->id)
        ->and($miss['context']['exception'])->toBe(TransportException::class)
        ->and(json_encode($miss['context']))->not->toContain($user->email);
});

it('reaches the recipient behind the one whose transport failed', function () {
    // The half a `via()` re-ordering could never fix: `Notification::send()` walks its recipients
    // sequentially too, so with the first recipient's mail throwing, the second recipient's record
    // was never attempted. `Tenant::notifyPortal()` sends to the company and then to every portal
    // login in exactly this shape.
    sw252TransportThatFails();
    $first = makeUser('manager', [$this->asset->id]);
    $second = makeUser('leasing', [$this->asset->id]);

    captureOpsLog(fn () => NotificationFacade::send([$first, $second], sw252InlineProbe()));

    expect($second->notifications()->where('data->type', 'sw252_probe')->exists())->toBeTrue()
        ->and($first->notifications()->where('data->type', 'sw252_probe')->exists())->toBeTrue();
});

it('still hands the message to the transport when it works — the control', function () {
    $tally = new stdClass;
    $tally->sent = 0;
    sw252TransportThatCounts($tally);
    $user = makeUser('manager', [$this->asset->id]);

    $ops = captureOpsLog(fn () => $user->notify(sw252InlineProbe()));

    expect($tally->sent)->toBe(1)
        ->and($user->notifications()->where('data->type', 'sw252_probe')->exists())->toBeTrue()
        ->and(collect($ops)->pluck('message'))->not->toContain('notification.delivery_failed');
});

it('leaves a fault INSIDE toMail() loud — only the transport family is best effort', function () {
    // Widen the catch to `Throwable` and this goes green for the wrong reason: a `TypeError` or a
    // missing view in a notification is a bug, and a bug that degrades to a warning line is one
    // nobody fixes. The transport exception family is exactly what an environment can do to us.
    sw252TransportThatFails();
    $user = makeUser('manager', [$this->asset->id]);

    $broken = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail', 'database'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            throw new RuntimeException('view [mail.does-not-exist] not found');
        }

        public function toArray(object $notifiable): array
        {
            return ['type' => 'sw252_broken'];
        }
    };

    expect(fn () => captureOpsLog(fn () => $user->notify($broken)))->toThrow(RuntimeException::class);
});

it('leaves a QUEUED notification\'s mail to the queue — retries and a failed_jobs row are worth more than a warning', function () {
    // Laravel dispatches one job per (recipient, channel) for a `ShouldQueue` notification, so its
    // database row is already isolated from its mail; and the mail job carries `tries=3` and lands
    // in `failed_jobs`, which `atriom:health` watches. Swallowing there would trade all of that for
    // one warning line. The sync driver runs the channel jobs inline and re-throws, which is what
    // makes the queue's verdict visible here.
    sw252TransportThatFails();
    $user = makeUser('manager', [$this->asset->id]);

    expect(fn () => $user->notify(new Sw252QueuedProbeNotification))->toThrow(TransportException::class);

    // The database channel's own job ran and its row stands. On the SYNC driver the channel jobs
    // run in `via()` order and the throw stops the sequence, so this holds because the probe lists
    // `database` first; on redis every channel job is dispatched before any runs, which is the
    // isolation the queue actually buys. The tooth here is the re-throw, not the ordering.
    expect($user->notifications()->where('data->type', 'sw252_queued_probe')->exists())->toBeTrue();
});

it('catches the 422 MailerSend does NOT re-wrap, and a network failure beneath the driver', function (Throwable $failure) {
    // `MailerSendTransport` re-wraps only `MailerSendHttpException` into Symfony's
    // `TransportException`. A 422 — the trial plan's unique-recipient cap, an unverified sender —
    // is `MailerSendValidationException`, a SIBLING, and a DNS/connect failure is the PSR-18
    // client's exception. Catch the Symfony family alone and the original defect reproduces the
    // day the 403 token is fixed.
    sw252TransportThatFails($failure);
    $user = makeUser('manager', [$this->asset->id]);

    $ops = captureOpsLog(fn () => $user->notify(sw252InlineProbe()));

    expect($user->notifications()->where('data->type', 'sw252_probe')->exists())->toBeTrue()
        ->and(collect($ops)->firstWhere('message', 'notification.delivery_failed')['context']['exception'])
        ->toBe($failure::class);
})->with([
    '422 validation' => fn () => new MailerSendException('[status code] 422: Trial accounts can only send to a limited set of recipients'),
    'network' => fn () => new NetworkException('cURL error 6: Could not resolve host: api.mailersend.com', new Request('POST', 'https://api.mailersend.com/v1/email')),
]);

it('keeps the record when the recipient\'s own address cannot be addressed', function () {
    // A malformed e-mail on the tenant's RECORD throws `RfcComplianceException` from the message
    // builder — data, not code. The operator fixes the address; the bell should be there when they
    // do, and the scan's stamp should not depend on it.
    $tenant = makeTenant(['email' => 'accounts at cilantro dot com']);

    $ops = captureOpsLog(fn () => $tenant->notify(sw252InlineProbe()));

    expect($tenant->notifications()->where('data->type', 'sw252_probe')->exists())->toBeTrue()
        ->and(collect($ops)->firstWhere('message', 'notification.delivery_failed')['context']['exception'])
        ->toBe(RfcComplianceException::class);
});

it('still throws for a notification with NO database channel — there is no record to protect', function () {
    // A password-reset link is mail and nothing else. Swallowing its failure prints "link sent" to
    // a person who cannot log in; and those resets were the only inline mail paths whose failure
    // reached the ERROR-line monitoring at all.
    sw252TransportThatFails();
    $user = makeUser('manager', [$this->asset->id]);

    $mailOnly = new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['mail'];
        }

        public function toMail(object $notifiable): MailMessage
        {
            return (new MailMessage)->subject('SW-252 mail-only probe')->line('reset link');
        }
    };

    expect(fn () => captureOpsLog(fn () => $user->notify($mailOnly)))->toThrow(TransportException::class);
});

it('re-throws to a caller that INSISTS — and the scope closes behind it', function () {
    sw252TransportThatFails();
    $user = makeUser('manager', [$this->asset->id]);

    expect(fn () => BestEffortMailChannel::requiringDelivery(fn () => $user->notify(sw252InlineProbe())))
        ->toThrow(TransportException::class);

    // The scope is a depth counter reset in `finally`: after the throw, the next ordinary send is
    // best-effort again, or one insisting caller would silence the record for the rest of the worker.
    captureOpsLog(fn () => $user->notify(sw252InlineProbe()));
    expect($user->notifications()->where('data->type', 'sw252_probe')->count())->toBe(1);
});

it('the invoice re-send still refuses when the mail did not go — the operator is entitled to know', function () {
    // `SendInvoiceToTenantService`'s whole reason is the e-mail with the PDF attached, and its
    // contract is stated in its own catch: *"the operator pressed send and is entitled to know it
    // did not go"*. The first draft of this seam made it stamp `tenant_notified_at` and toast
    // "sent" over a message that never left. It insists now.
    $this->seed(ChartOfAccountsSeeder::class);
    $this->seed(AccountMappingSeeder::class);
    sw252TransportThatFails();

    $lease = makeLease(makeUnit($this->asset), null, ['status' => 'active']);
    makeTenantUser($lease->tenant);
    $invoice = makeInvoice($lease, ['status' => 'issued']);

    expect(fn () => captureOpsLog(fn () => app(SendInvoiceToTenantService::class)->send($invoice)))
        ->toThrow(DomainException::class, __('admin.errors.invoice_send_failed'));

    expect($invoice->fresh()->tenant_notified_at)->toBeNull();
});

it('is the mail channel the app actually resolves — the binding is pinned', function () {
    // `ChannelManager::createMailDriver()` is `$this->container->make(MailChannel::class)`; a
    // framework release that constructed it with `new` would silently remove every tooth above.
    expect(app(ChannelManager::class)->driver('mail'))->toBeInstanceOf(BestEffortMailChannel::class);
});

it('the sales-declaration chase now survives the transport: both records, the finding, the stamp', function () {
    // The instance that found it. A percentage-rent lease a year into its term, no declaration for
    // last month, a company with one portal login — and a transport that answers 403.
    sw252TransportThatFails();

    $tenant = makeTenant();
    $login = makeTenantUser($tenant);
    $lease = makeLease(makeUnit($this->asset), $tenant, [
        'has_percentage_rent' => true,
        'commencement_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);
    $period = now()->subMonthNoOverflow()->format('Y-m');

    expect(Lease::missingSalesDeclarationsFor(now()->toImmutable()->subMonthNoOverflow()->startOfMonth())->pluck('id'))
        ->toContain($lease->id);

    $ops = captureOpsLog(function () use ($period) {
        $this->artisan('sales:scan-missing-declarations', ['--period' => $period])->assertSuccessful();
    });

    // The company's row — the mobile app's reminder and the scan's idempotency stamp…
    $stamp = fn (Tenant $t) => $t->notifications()
        ->where('data->type', 'sales_declaration_reminder')
        ->where('data->lease_id', $lease->id)
        ->where('data->period_key', $period);
    expect($stamp($tenant)->exists())->toBeTrue();

    // …the portal login's row, which is what the portal bell shows and which used to be the
    // recipient behind the failure…
    expect($login->notifications()->where('data->type', 'sales_declaration_reminder')->exists())->toBeTrue();

    // …the FINDING, recorded before any delivery so the daily report can tell "ran, found two"
    // from "never ran" (SW-244's rule)…
    $finding = collect($ops)->firstWhere('message', 'sales.declarations_missing');
    expect($finding)->not->toBeNull()
        ->and($finding['context']['period'])->toBe($period)
        ->and($finding['context']['leases'])->toContain($lease->reference);

    // …and the two misses (company, login) are each on the record by recipient.
    expect(collect($ops)->where('message', 'notification.delivery_failed')->count())->toBe(2);

    // The stamp holds: a second run chases nobody again.
    captureOpsLog(function () use ($period) {
        $this->artisan('sales:scan-missing-declarations', ['--period' => $period])
            ->expectsOutputToContain('1 already reminded')
            ->assertSuccessful();
    });
    expect($stamp($tenant)->count())->toBe(1);
});

it('the chase does not record a finding on a dry run', function () {
    $tenant = makeTenant();
    makeLease(makeUnit($this->asset), $tenant, [
        'has_percentage_rent' => true,
        'commencement_date' => now()->subYear()->toDateString(),
        'expiry_date' => now()->addYear()->toDateString(),
    ]);

    $ops = captureOpsLog(function () {
        $this->artisan('sales:scan-missing-declarations', ['--period' => now()->subMonthNoOverflow()->format('Y-m'), '--dry-run' => true])
            ->assertSuccessful();
    });

    expect(collect($ops)->pluck('message'))->not->toContain('sales.declarations_missing')
        ->and($tenant->notifications()->count())->toBe(0);
});
