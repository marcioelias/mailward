<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Models\Mail\Scopes\DomainScope;
use Illuminate\Database\Eloquent\Builder;
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

    protected static function booted(): void
    {
        static::addGlobalScope(new DomainScope);
    }

    /**
     * The column holding the domain this record belongs to, or null when the
     * model is not scoped by domain and its authorisation is expressed some
     * other way.
     *
     * `used_quota` and `last_login` return null on purpose. Both carry a
     * `domain` column, but `used_quota.domain` is filled by a trigger that
     * exists on MySQL and nowhere else, so scoping on it would silently return
     * nothing on PostgreSQL (docs/reference/schema-type-matrix.md, D14). They
     * are read through their owning mailbox, which is scoped.
     */
    public function domainScopeColumn(): ?string
    {
        return 'domain';
    }

    /**
     * Drop the domain restriction from a query.
     *
     * `docs/policies/authorization.md` §3 requires this to be explicit and
     * visible at the call site, and it is: every use reads as a deliberate
     * widening rather than an absent filter. Legitimate uses are
     * authentication, which runs before an actor exists, console commands,
     * which have no request, and a global admin operating deliberately across
     * every domain.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWithoutDomainScope($query)
    {
        return $query->withoutGlobalScope(DomainScope::class);
    }
}
