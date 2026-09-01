<?php

use App\Filament\Admin\Pages\AssistantQuestions;
use App\Models\AssistantQuestion;
use Database\Seeders\RolesPermissionsSeeder;
use Livewire\Livewire;

/**
 * The assistant's miss list — docs/modules/39-assistant.md.
 *
 * This screen is the deliverable of the whole A phase: it is what decides whether a language model
 * would buy anything, and which screen guides have a hole. The properties worth pinning are that it
 * GROUPS (one person asking six times is not six problems), that it stays inside its property, and
 * that reading other people's words is granted rather than assumed.
 */
beforeEach(fn () => $this->seed(RolesPermissionsSeeder::class));

function askedIn(App\Models\Asset $asset, string $question, bool $matched, int $times = 1): void
{
    foreach (range(1, $times) as $ignored) {
        AssistantQuestion::create([
            'asset_id' => $asset->id,
            'question' => $question,
            'question_folded' => App\Support\Search\SearchText::normalize($question),
            'locale' => 'en',
            'matched' => $matched,
            'top_key' => $matched ? 'credit_notes' : null,
            'top_score' => $matched ? 10 : 0,
            'result_count' => $matched ? 1 : 0,
        ]);
    }
}

it('counts one question once, however it was spelled', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    // Folded, so casing and Arabic spelling variants collapse. Without this the list is a log,
    // and a log cannot be prioritised.
    askedIn($asset, 'Credit Note', true);
    askedIn($asset, 'credit note', true);
    askedIn($asset, 'CREDIT NOTE', true);

    asTenant($asset, function () {
        $rows = Livewire::test(AssistantQuestions::class)->assertOk()->instance()->getTableRecords();

        expect($rows)->toHaveCount(1)
            ->and($rows->first()->asked)->toBe(3);
    });
});

it('tells a question answered sometimes from one answered never', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    // Two different problems with two different fixes: sometimes-answered is a RANKING issue,
    // never-answered is a missing screen guide. A yes/no column would hide the difference.
    askedIn($asset, 'what happens when a cheque bounces', matched: true, times: 1);
    askedIn($asset, 'what happens when a cheque bounces', matched: false, times: 2);
    askedIn($asset, 'how do I export the owner pack', matched: false, times: 4);

    asTenant($asset, function () {
        $rows = Livewire::test(AssistantQuestions::class)->instance()->getTableRecords()->keyBy('question_folded');

        expect((int) $rows->get('what happens when a cheque bounces')->answered)->toBe(1)
            ->and((int) $rows->get('what happens when a cheque bounces')->asked)->toBe(3)
            ->and((int) $rows->get('how do i export the owner pack')->answered)->toBe(0)
            ->and((int) $rows->get('how do i export the owner pack')->asked)->toBe(4);
    });
});

it('ranks the most-asked first, because a feed is read once', function () {
    $asset = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    // Seeded so insertion order DISAGREES with the intended order — otherwise the assertion
    // passes on a table that is not sorting at all.
    askedIn($asset, 'asked twice', false, times: 2);
    askedIn($asset, 'asked five times', false, times: 5);
    askedIn($asset, 'asked once', false, times: 1);

    asTenant($asset, function () {
        $rows = Livewire::test(AssistantQuestions::class)->instance()->getTableRecords();

        expect(array_map(fn ($r): int => (int) $r->asked, $rows->all()))->toBe([5, 2, 1]);
    });
});

it('shows this property only', function () {
    $mine = makeAsset();
    $theirs = makeAsset();
    $this->actingAs(makeUser('super_admin'));

    askedIn($mine, 'a question asked in my mall', false);
    askedIn($theirs, 'a question asked in the other mall', false);

    asTenant($mine, function () {
        $questions = Livewire::test(AssistantQuestions::class)->instance()
            ->getTableRecords()->pluck('question')->implode(' ');

        // The refusal, and the control in the same shape.
        expect($questions)->not->toContain('the other mall')
            ->and($questions)->toContain('my mall');
    });
});

it('is granted, not assumed — unlike the box itself', function () {
    $asset = makeAsset();

    // The box needs no permission: every result is filtered through the target screen's own
    // canAccess(). Reading what OTHER people typed is a different act — free text that can name
    // a tenant — so it is a grant.
    $this->actingAs(makeUser('viewer'));
    expect(AssistantQuestions::canAccess())->toBeFalse()
        ->and(App\Filament\Admin\Pages\Assistant::canAccess())->toBeTrue();

    $this->actingAs(makeUser('super_admin'));
    expect(AssistantQuestions::canAccess())->toBeTrue();
});

it('refuses the screen over HTTP to a role that does not hold the right', function () {
    $asset = makeAsset();

    $this->actingAs(makeUser('viewer', [$asset->id]));
    $this->get(AssistantQuestions::getUrl(tenant: $asset))->assertForbidden();

    // `AuthenticateSession` logs the second user out when `actingAs` is swapped between two HTTP
    // requests in one test — the control then answers a redirect, and the redirect blows up inside
    // the session middleware as a 500. Without the flush this test reports a broken page and the
    // refusal above passes for the wrong reason.
    $this->flushSession();

    // The control: with the right, the page really renders — a refusal test with no control
    // passes just as happily when the route is broken for everyone.
    $this->actingAs(makeUser('super_admin', [$asset->id]));
    $this->get(AssistantQuestions::getUrl(tenant: $asset))->assertOk();
});
