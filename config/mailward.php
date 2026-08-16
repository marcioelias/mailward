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

    /*
    |--------------------------------------------------------------------------
    | Mail storage layout
    |--------------------------------------------------------------------------
    |
    | How `mailbox.maildir` is built. Configuration rather than a constant
    | (docs/decisions/0007-configurable-maildir-and-password-scheme.md):
    | iRedMail assembles the path from installer settings, and a server
    | migrated from an older install deliberately preserves the layout it
    | already had.
    |
    | These must match the mail server. Nothing reads the column back, but a
    | value that disagrees with what Dovecot expects produces an account whose
    | mail lands somewhere nobody looks.
    |
    */

    'maildir' => [
        'base' => env('MAILWARD_STORAGE_BASE', '/var/vmail'),
        'node' => env('MAILWARD_STORAGE_NODE', 'vmail1'),
        'hashed' => env('MAILWARD_MAILDIR_HASHED', true),
        'prepend_domain' => env('MAILWARD_MAILDIR_PREPEND_DOMAIN', true),
        'append_timestamp' => env('MAILWARD_MAILDIR_APPEND_TIMESTAMP', true),
    ],

    'audit' => [
        'retention_days' => env('MAILWARD_AUDIT_RETENTION_DAYS'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Dashboard
    |--------------------------------------------------------------------------
    |
    | How many days without a login make an account dormant
    | (docs/features/dashboard.md BR-20). The last login is the greater of
    | `last_login.imap` and `last_login.pop3`; `lda` is excluded, because it
    | records a delivery into the account rather than a person connecting to
    | it, and an account nobody has read for two years looks active under it
    | for as long as anything still sends mail there.
    |
    | Ninety days has no external basis. It is a chosen number, which is why
    | the dashboard states the threshold in force beside the figure instead of
    | leaving the reader to guess which one produced it.
    |
    */

    'dashboard' => [
        'dormant_after_days' => env('MAILWARD_DORMANT_AFTER_DAYS', 90),
    ],

];
