<?php

use App\Providers\AppServiceProvider;
use Illuminate\Support\Facades\Mail;
use MailerSend\LaravelDriver\MailerSendTransport;

/**
 * Guards the outbound-email plumbing: the MailerSend transport stays wired, and the
 * non-production catch-all stays honest about the one case where it must NOT fire.
 *
 * Nothing here leaves the box — phpunit.xml pins MAIL_MAILER=array.
 */
it('resolves the mailersend transport from config', function () {
    // A real key shape is required only to build the SDK client; nothing is sent.
    config()->set('mailersend-driver.api_key', 'mlsn.test-key');

    expect(Mail::mailer('mailersend')->getSymfonyTransport())
        ->toBeInstanceOf(MailerSendTransport::class);
});

it('redirects every outgoing email to the catch-all outside production', function () {
    config()->set('mail.always_to', 'catchall@atriom.test');

    (new AppServiceProvider(app()))->configureMailCatchAll();

    Mail::raw('hello', fn ($m) => $m->to('tenant@atriomwalk.test')->subject('Rent'));

    $sent = Mail::mailer()->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe('catchall@atriom.test');
});

it('never redirects in production — a stray catch-all must not swallow tenant mail', function () {
    config()->set('mail.always_to', 'catchall@atriom.test');
    inEnvironment('production');

    (new AppServiceProvider(app()))->configureMailCatchAll();

    Mail::raw('hello', fn ($m) => $m->to('tenant@atriomwalk.test')->subject('Rent'));

    $sent = Mail::mailer()->getSymfonyTransport()->messages();

    expect($sent[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe('tenant@atriomwalk.test');
});

it('refuses to send while the from-address is still the placeholder', function () {
    config()->set('mail.from.address', 'noreply@REPLACE-WITH-VERIFIED-MAILERSEND-DOMAIN');

    $this->artisan('mail:test', ['recipient' => 'someone@example.com'])
        ->expectsOutputToContain('MAIL_FROM_ADDRESS is still the placeholder')
        ->assertExitCode(1);

    expect(Mail::mailer()->getSymfonyTransport()->messages())->toBeEmpty();
});

it('sends one message through the configured mailer', function () {
    config()->set('mail.from.address', 'noreply@tri-tech.net');
    config()->set('mail.always_to', null);

    $this->artisan('mail:test', ['recipient' => 'operator@example.com'])
        ->assertExitCode(0);

    $sent = Mail::mailer()->getSymfonyTransport()->messages();

    expect($sent)->toHaveCount(1)
        ->and($sent[0]->getEnvelope()->getSender()->getAddress())->toBe('noreply@tri-tech.net')
        ->and($sent[0]->getEnvelope()->getRecipients()[0]->getAddress())->toBe('operator@example.com')
        ->and($sent[0]->getOriginalMessage()->getHtmlBody())->toContain('Outbound email is working');
});
