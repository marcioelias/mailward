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

    /*
    |--------------------------------------------------------------------------
    | Audit log retention
    |--------------------------------------------------------------------------
    |
    | How many days of audit entries to keep. Empty means keep everything, and
    | that is the default.
    |
    | Setting a window is a deliberate weakening of the log's guarantee
    | (docs/features/audit-log.md BR-05): entries are still never modified and
    | never deleted individually, but everything older than the window is
    | removed wholesale by a scheduled job. Anything that must outlive the
    | window has to be exported before it passes.
    |
    */

    'audit' => [
        'retention_days' => env('MAILWARD_AUDIT_RETENTION_DAYS'),
    ],

];
