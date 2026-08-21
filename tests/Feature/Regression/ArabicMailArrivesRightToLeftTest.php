<?php

/*
|--------------------------------------------------------------------------
| An Arabic email arrives right-to-left
|--------------------------------------------------------------------------
| `resources/views/vendor/mail` did not exist, so every `MailMessage` notification — eleven of them,
| including the SLA breach alert and the overdue notice — rendered Arabic inside Laravel's stock
| left-aligned frame. One bespoke template (`emails/invoice-issued`) had solved it for itself; the
| other twenty-two surfaces had not.
|
| Driven through Laravel's own renderer rather than asserted against the blade source, because what
| matters is the HTML that reaches the recipient. A `dir` attribute present in a template that some
| other layer overrides is not a fixed email.
|
| **This is also an upgrade guard.** The published views are a fork of the framework's; a future
| `vendor:publish --force` or a hand-merge that drops the attribute would silently un-fix this, and
| nothing else in the suite reads mail HTML.
*/

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;

/** The HTML a `MailMessage` actually produces in a given locale. */
function renderedMailHtml(string $locale): string
{
    app()->setLocale($locale);

    $message = (new MailMessage)
        ->subject('Test')
        ->line('A line of body copy.')
        ->action('Open', 'https://example.test');

    return (string) app(Markdown::class)->render($message->markdown ?: 'mail::message', $message->data());
}

it('sends Arabic mail in a right-to-left frame', function () {
    $html = renderedMailHtml('ar');

    expect($html)->toContain('dir="rtl"')
        ->and($html)->toContain('lang="ar"');
});

it('leaves English mail exactly as it was — the control', function () {
    // Without this, a change that hard-coded `rtl` everywhere would satisfy the case above.
    $html = renderedMailHtml('en');

    expect($html)->toContain('dir="ltr"')
        ->and($html)->toContain('lang="en"');
});

it('aligns body copy by direction, not to the left', function () {
    // Asserted against the THEME SOURCE, not the rendered HTML, and the reason is worth stating.
    //
    // Laravel inlines this stylesheet with `css-to-inline-styles`, which normalises `text-align:
    // left` away entirely — with the bug restored, the rendered `<p>` carries NO text-align at all,
    // so both `left` and `start` are absent from the output. A rendered-HTML assertion therefore
    // cannot tell the fixed theme from the broken one: my first version of this case passed with
    // `text-align: left` put back, because the `start` it looked for came from OTHER rules in the
    // same file.
    //
    // The frame is checked behaviourally above. This half is a source rule, because that is the
    // only place the difference exists.
    $theme = (string) file_get_contents(resource_path('views/vendor/mail/html/themes/default.css'));

    // `str_contains()` + `toBeFalse()`, not `->not->toContain($needle, $message)`. Pest's
    // `toContain()` takes VARIADIC needles, so a "message" second argument becomes a SECOND needle —
    // and under `not` that made the whole expectation pass with the bug restored. Measured: putting
    // `text-align: left` back left this case green until it was written this way.
    expect(str_contains($theme, 'text-align: left'))
        ->toBeFalse('The mail theme pins text to the left edge, which is the wrong side of an Arabic email.');

    expect(str_contains($theme, 'border-left: #18181b solid 4px'))
        ->toBeFalse('The panel accent bar sits on the far side of an Arabic panel, where it reads as a stray rule.');

    // The premise: this file exists and is the one the renderer uses. Without the published copy,
    // the assertions above pass over an empty string.
    expect($theme)->toContain('text-align: start')
        ->and(config('mail.markdown.theme', 'default'))->toBe('default');
});
