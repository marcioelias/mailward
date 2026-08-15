<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;
use Spatie\Activitylog\Models\Activity;

/**
 * One recorded write, in Mailward's own database.
 *
 * `docs/policies/authorization.md` §7 names what a row must carry: the acting
 * address, the action, the target, the before and after values, the IP and the
 * timestamp. The package supplies all of that except the address and the IP,
 * so those are columns of our own — indexed, because "what did this
 * administrator do" and "what came from this address" are the two questions
 * the log exists to answer.
 *
 * The actor is stored denormalised rather than only as a morph key, because
 * the log outlives the accounts it refers to: it is append-only and
 * deliberately keeps references to mailboxes that have been deleted
 * (`docs/02-domain.md` §13).
 *
 * @property ?string $actor
 * @property ?string $ip_address
 */
final class AuditEntry extends Activity
{
    protected $table = 'audit_log';

    protected static function booted(): void
    {
        /*
         * A technical concern, not a business rule: stamping who and from
         * where, at the moment the row is written. Doing it here rather than
         * at each call site is what stops one forgotten argument from
         * producing an unattributable entry.
         */
        self::creating(function (self $entry): void {
            $entry->actor ??= self::currentActor();
            $entry->ip_address ??= Request::ip();
        });
    }

    private static function currentActor(): string
    {
        $identifier = Auth::user()?->getAuthIdentifier();

        // A write with no authenticated actor is a console run — the
        // documented recovery path in docs/policies/authorization.md §5 is
        // exactly that, and it still has to be attributable.
        return is_string($identifier) && $identifier !== '' ? $identifier : 'console';
    }
}
