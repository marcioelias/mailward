<?php

declare(strict_types=1);

namespace App\Models\Mail;

use Illuminate\Database\Eloquent\Model;

/**
 * Base class for every model bound to an iRedMail database.
 *
 * These models describe a schema Mailward does not own. iRedMail's installer
 * creates it and iRedMail's own upgrade scripts change it; Mailward issues DML
 * only — SELECT, INSERT, UPDATE, DELETE. No model below this class is ever
 * referenced by a migration, and the `vmail` database user is granted no DDL
 * privilege, so a mistake fails at the server rather than at our discipline
 * (docs/01-architecture.md §2 and §3).
 *
 * Two consequences are shared by every subclass and live here:
 *
 * - The tables carry `created` / `modified` columns rather than Laravel's
 *   `created_at` / `updated_at`, so Eloquent's timestamp handling is off and
 *   those columns are written explicitly (docs/02-domain.md §1.5).
 * - `$guarded = []` is never used. Every concrete model declares an explicit
 *   `$fillable` listing only the columns Mailward is allowed to write.
 */
abstract class MailModel extends Model
{
    protected $connection = 'vmail';

    public $timestamps = false;

    // The default domain scope required by docs/policies/authorization.md §3
    // belongs on this class, and is added once authentication exists: it has to
    // read the administered domains of the acting administrator, and there is
    // no authenticated actor yet.
}
