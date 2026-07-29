<?php

/*
|--------------------------------------------------------------------------
| Alerts with a clock on them must leave the app
|--------------------------------------------------------------------------
| Fourteen notifications shipped as `['database']` — the in-app bell and nothing else — including
| every one with a deadline attached: both SLA breaches, a vendor certificate lapsing, a contract
| past its notice deadline (after which it auto-renews and commits money), and the general ledger
| refusing to accept documents. A bell only alerts someone who opens the app, and the person who
| can still act on a breached SLA is by definition not sitting in /admin.
|
| The mail body is DERIVED from the bell payload (AlsoSendsByMail), not written twice — two
| hand-maintained copies of one alert drift, and then the email and the bell disagree about what
| happened. These tests pin both halves: that the channel is there at all, and that the two say
| the same thing.
*/

use App\Notifications\Concerns\AlsoSendsByMail;
use App\Notifications\DepartmentMessageNotification;
use App\Notifications\LedgerSyncFailedNotification;
use App\Notifications\LowStockNotification;
use App\Notifications\OwnerStatementSentNotification;
use App\Notifications\SalesDeclarationSubmittedNotification;
use App\Notifications\TenantRequestSlaBreachedNotification;
use App\Notifications\VendorContractRenewalDueNotification;
use App\Notifications\VendorDocumentExpiringNotification;
use App\Notifications\WorkOrderSlaBreachedNotification;
use Database\Seeders\RolesPermissionsSeeder;

/** Every notification that must reach a person who is not looking at the app. */
const OFF_APP_ALERTS = [
    LedgerSyncFailedNotification::class,
    TenantRequestSlaBreachedNotification::class,
    WorkOrderSlaBreachedNotification::class,
    VendorDocumentExpiringNotification::class,
    VendorContractRenewalDueNotification::class,
];

it('sends every deadline-bearing alert by mail as well as the bell', function () {
    $bellOnly = [];
    $lostTheBell = [];

    foreach (OFF_APP_ALERTS as $class) {
        // via() is declared on each class and does not touch the notifiable, so it can be read
        // off an uninitialised instance — no fixture per notification just to assert a channel.
        $via = (new ReflectionClass($class))->newInstanceWithoutConstructor()->via(new stdClass);

        // Collected rather than asserted inline: Pest's toContain() reads a second argument as
        // ANOTHER expected value, not a failure message, so the "helpful" message silently
        // becomes part of what must be in the array.
        if (! in_array('mail', $via, true)) {
            $bellOnly[] = class_basename($class);
        }
        if (! in_array('database', $via, true)) {
            $lostTheBell[] = class_basename($class);
        }
    }

    expect($bellOnly)->toBe([], 'These have a clock on them but never leave the app: '.implode(', ', $bellOnly))
        ->and($lostTheBell)->toBe([], 'These stopped ringing the bell: '.implode(', ', $lostTheBell));
});

it('derives the mail from the bell payload so the two cannot disagree', function () {
    foreach (OFF_APP_ALERTS as $class) {
        expect(in_array(AlsoSendsByMail::class, class_uses_recursive($class), true))
            ->toBeTrue("[{$class}] should derive its mail from toDatabase(), not hand-write a second copy");
    }
});

it('puts the bell title and body into the email, with the bell severity', function () {
    $this->seed(RolesPermissionsSeeder::class);

    $notification = new LedgerSyncFailedNotification(3);
    $recipient = makeUser('accounting');

    $bell = $notification->toDatabase($recipient);
    $mail = $notification->toMail($recipient);

    expect($mail->subject)->toBe($bell['title'])
        ->and($mail->introLines)->toContain($bell['body'])
        // The bell paints this red; the email must not arrive looking like a newsletter.
        ->and($bell['color'])->toBe('danger')
        ->and($mail->level)->toBe('error')
        // And a way back in.
        ->and($mail->actionUrl)->not->toBeEmpty();
});

it('leaves the read-when-you-look notifications on the bell alone', function () {
    // Off-app delivery is a cost as well as a feature: mail everything and people learn to ignore
    // the ones that matter. These are deliberately in-app only — if one ever needs mail, it should
    // be a decision, not a side effect of someone copying a trait around.
    $inAppOnly = [
        DepartmentMessageNotification::class,
        OwnerStatementSentNotification::class,
        SalesDeclarationSubmittedNotification::class,
        LowStockNotification::class,
    ];

    $unexpectedlyMailed = [];

    foreach ($inAppOnly as $class) {
        $via = (new ReflectionClass($class))->newInstanceWithoutConstructor()->via(new stdClass);

        if (in_array('mail', $via, true)) {
            $unexpectedlyMailed[] = class_basename($class);
        }
    }

    expect($unexpectedlyMailed)->toBe([], implode('', [
        'These were deliberately bell-only and now mail: '.implode(', ', $unexpectedlyMailed).'. ',
        'Adding mail here needs a reason — blanket delivery trains people to ignore the alerts that matter.',
    ]));
});
