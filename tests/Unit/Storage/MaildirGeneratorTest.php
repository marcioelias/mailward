<?php

declare(strict_types=1);

use App\Support\Storage\MaildirGenerator;

/**
 * The layout observed on the server being migrated:
 * /var/vmail/vmail1/<domain>/<a>/<b>/<c>/<user>-<date>/Maildir/
 * (docs/reference/observed-install.md §2).
 */
it('builds the observed layout', function () {
    $maildir = (new MaildirGenerator)->forAddress('joao@example.test');

    expect($maildir)->toStartWith('example.test/j/o/a/joao-')
        ->and($maildir)->toEndWith('/');
});

it('lower cases the whole path', function () {
    expect((new MaildirGenerator)->forAddress('JOAO@Example.TEST'))
        ->toStartWith('example.test/j/o/a/joao-');
});

it('yields fewer levels for a short local part rather than failing', function () {
    // iRedMail's own behaviour here is unconfirmed, and since nothing reads
    // the path back, a flatter tree costs nothing.
    expect((new MaildirGenerator)->forAddress('a@example.test'))
        ->toStartWith('example.test/a/a-');
});

it('can be configured flat, without hashing or a timestamp', function () {
    $generator = new MaildirGenerator(hashed: false, appendTimestamp: false);

    expect($generator->forAddress('joao@example.test'))->toBe('example.test/joao/');
});

it('can be configured without the domain prefix', function () {
    $generator = new MaildirGenerator(prependDomain: false, appendTimestamp: false);

    expect($generator->forAddress('joao@example.test'))->toBe('j/o/a/joao/');
});

it('joins the three columns into the absolute path a deletion record needs', function () {
    // The relative tail alone would leave iRedMail's cron resolving a path
    // that does not exist, and the files would outlive the account.
    expect(MaildirGenerator::absolutePath('/var/vmail', 'vmail1', 'example.test/j/o/a/joao-2026.08.15/'))
        ->toBe('/var/vmail/vmail1/example.test/j/o/a/joao-2026.08.15');
});
