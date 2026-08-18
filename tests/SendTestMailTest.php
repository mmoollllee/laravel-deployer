<?php

/*
 * MAIL_MAILER=array — the mail lands in the array transport, whose contents are
 * asserted directly.
 */

it('sends a test mail to the given recipient', function () {
    $this->artisan('app:send-test-mail', ['recipient' => 'ziel@example.test'])
        ->assertSuccessful();

    $messages = app('mailer')->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getEnvelope()->getRecipients()[0]->getAddress())->toBe('ziel@example.test');
});

it('falls back to the configured from address as recipient', function () {
    config(['mail.from.address' => 'kontaktformular@example.test']);

    $this->artisan('app:send-test-mail')->assertSuccessful();

    $messages = app('mailer')->getSymfonyTransport()->messages();

    expect($messages)->toHaveCount(1)
        ->and($messages->first()->getEnvelope()->getRecipients()[0]->getAddress())->toBe('kontaktformular@example.test');
});

it('fails cleanly when no recipient can be resolved', function () {
    config(['mail.from.address' => null]);

    $this->artisan('app:send-test-mail')->assertFailed();

    expect(app('mailer')->getSymfonyTransport()->messages())->toHaveCount(0);
});

/**
 * The transport exception carries the actual diagnosis — an SMTP 535, a refused
 * connection — so it has to reach the operator instead of being swallowed into
 * a bare non-zero exit.
 */
it('reports the transport failure instead of swallowing it', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp' => [
        'transport' => 'smtp',
        'host' => 'localhost',
        'port' => 1,          // nothing listens here
        'timeout' => 1,
    ]]);

    $this->artisan('app:send-test-mail', ['recipient' => 'ziel@example.test'])
        ->expectsOutputToContain('Versand fehlgeschlagen:')
        ->assertFailed();
});
