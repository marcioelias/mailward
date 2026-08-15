<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Password scheme
    |--------------------------------------------------------------------------
    |
    | The scheme Mailward generates when it writes a password. It must match
    | what the mail server is configured to accept, because the value written
    | here is the account's real mail password: Dovecot reads the same column.
    |
    | Every supported scheme is always *verifiable*, whatever this is set to.
    | Only generation is chosen here
    | (docs/decisions/0007-configurable-maildir-and-password-scheme.md).
    |
    | A current iRedMail generates SSHA512 on Linux and BLF-CRYPT on the BSDs.
    |
    */

    'password' => [
        'scheme' => env('MAILWARD_PASSWORD_SCHEME', 'SSHA512'),
    ],

];
