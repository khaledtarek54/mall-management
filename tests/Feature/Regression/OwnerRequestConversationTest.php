<?php

use App\Models\OwnerRequest;
use App\Services\OwnerRequestService;
use Illuminate\Support\Facades\Notification;

/**
 * Owner requests are a communication CHANNEL, but had no conversation (module 15). The whole thing
 * was the owner's opening message + a single `resolution_notes` the operator overwrote — and which
 * was silently DROPPED unless the status was set to `resolved`. These pin the reply thread: every
 * message is saved regardless of status, in order, and notifies the other party.
 */
beforeEach(function () {
    $this->svc = app(OwnerRequestService::class);
    $this->owner = makeUser('owner');
    $this->operator = makeUser('manager');
    $this->request = $this->svc->create([
        'subject' => 'Facade budget split',
        'body' => 'Please confirm the 60/40 split for the facade works.',
        'recipient' => 'operator',
    ], $this->owner);
});

it('records a reply and keeps the whole thread in order', function () {
    $this->svc->reply($this->request, $this->operator, 'Reviewing with the contractor now.');
    $this->svc->reply($this->request, $this->owner, 'Thanks — need it by Sunday.');

    $bodies = $this->request->refresh()->replies->pluck('body')->all();

    expect($this->request->replies)->toHaveCount(2)
        ->and($bodies)->toBe([
            'Reviewing with the contractor now.', // oldest first
            'Thanks — need it by Sunday.',
        ])
        ->and($this->request->replies->first()->author_id)->toBe($this->operator->id);
});

it('saves the reply even when the status does NOT move (the old bug)', function () {
    // A mid-conversation reply with no status change — used to be dropped entirely.
    $this->svc->reply($this->request, $this->operator, 'Still gathering quotes.');

    expect($this->request->refresh()->replies)->toHaveCount(1)
        ->and($this->request->status)->toBe('open'); // unchanged
});

it('lets an optional status move ride along with a reply', function () {
    $this->svc->reply($this->request, $this->operator, 'Agreed — 60/40 confirmed.', 'resolved');

    $this->request->refresh();
    expect($this->request->status)->toBe('resolved')
        ->and($this->request->resolved_at)->not->toBeNull()
        ->and($this->request->replies)->toHaveCount(1);
});

it('refuses a reply once the request is terminal, and refuses an empty one', function () {
    $this->request->update(['status' => 'closed']);

    expect(fn () => $this->svc->reply($this->request, $this->operator, 'too late'))
        ->toThrow(DomainException::class);

    $this->request->update(['status' => 'open']);
    expect(fn () => $this->svc->reply($this->request, $this->operator, '   '))
        ->toThrow(DomainException::class)
        ->and($this->request->refresh()->replies)->toHaveCount(0);
});

it('notifies the owner when the operator replies (not the operator author)', function () {
    Notification::fake();

    $this->svc->reply($this->request, $this->operator, 'On it.');

    // The owner who raised it is belled; the operator (author) is not.
    Notification::assertSentTo($this->owner, \App\Notifications\OwnerRequestNotification::class);
    Notification::assertNotSentTo($this->operator, \App\Notifications\OwnerRequestNotification::class);
});
