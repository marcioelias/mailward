<?php

declare(strict_types=1);

namespace App\Support\Storage;

use Illuminate\Support\Str;

/**
 * Builds the value written to `mailbox.maildir`.
 *
 * **This is the relative tail, not an absolute path.** Dovecot's shipped
 * `user_query` concatenates `storagebasedirectory`, `storagenode` and this
 * value; only `deleted_mailboxes.maildir` is absolute
 * (`docs/reference/observed-install.md` §2).
 *
 * The layout is configuration rather than a constant
 * (`docs/decisions/0007-configurable-maildir-and-password-scheme.md`): iRedMail
 * assembles it from installer settings, and the first deployment deliberately
 * preserves a layout inherited from an older install. A hard-coded algorithm
 * would be correct on one server and wrong on the next.
 *
 * The observed shape, and the default here:
 *
 *     <domain>/<a>/<b>/<c>/<local-part>-<timestamp>/
 *
 * where a, b and c are the first three characters of the local part. Nothing
 * parses this column back — Dovecot creates the directory itself from whatever
 * it finds — so the value only has to be unique, lower case, and stable once
 * written.
 */
final class MaildirGenerator
{
    public function __construct(
        private readonly bool $hashed = true,
        private readonly bool $prependDomain = true,
        private readonly bool $appendTimestamp = true,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            hashed: (bool) config('mailward.maildir.hashed', true),
            prependDomain: (bool) config('mailward.maildir.prepend_domain', true),
            appendTimestamp: (bool) config('mailward.maildir.append_timestamp', true),
        );
    }

    /**
     * @param  string  $address  the full, already lower-cased mail address
     */
    public function forAddress(string $address): string
    {
        [$local, $domain] = array_pad(explode('@', Str::lower($address), 2), 2, '');

        $segments = [];

        if ($this->prependDomain && $domain !== '') {
            $segments[] = $domain;
        }

        if ($this->hashed) {
            /*
             * One directory level per character, from the first three of the
             * local part. A local part shorter than three characters simply
             * yields fewer levels — iRedMail's own behaviour there is
             * unconfirmed, and since nothing reads the path back, fewer levels
             * costs nothing but a slightly flatter tree.
             */
            foreach (str_split(substr($local, 0, 3)) as $character) {
                $segments[] = $character;
            }
        }

        $leaf = $local;

        if ($this->appendTimestamp) {
            /*
             * What makes the path unique. Two accounts at the same address
             * cannot coexist, but an address deleted and re-created must not
             * land on the directory the old one left behind — the files are
             * removed by iRedMail's cron on its own schedule, not at the moment
             * of deletion (docs/02-domain.md §11).
             */
            $leaf .= '-'.now()->format('Y.m.d.H.i.s');
        }

        $segments[] = $leaf;

        return implode('/', $segments).'/';
    }

    /**
     * The absolute path, for the `deleted_mailboxes` row that tells iRedMail's
     * cron which files to remove. Writing the relative tail there would leave
     * the cron resolving a path that does not exist, and the files would
     * outlive the account in silence (BR-24).
     */
    public static function absolutePath(string $base, string $node, string $maildir): string
    {
        $parts = array_filter(
            [trim($base, '/'), trim($node, '/'), trim($maildir, '/')],
            static fn (string $part): bool => $part !== '',
        );

        return '/'.implode('/', $parts);
    }
}
