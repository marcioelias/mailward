<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Casts\IntegerBoolean;
use App\Casts\NeverExpiresDate;
use App\Casts\NeverSetDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The grant that makes `username` an administrator of `domain` — table
 * `domain_admins` (docs/02-domain.md §7).
 *
 * **The real primary key is composite, `(username, domain)`.** Eloquent cannot
 * model that, so `$primaryKey` names only `username`, which is not unique in
 * this table: `find()`, `findOrFail()` and route model binding are meaningless
 * here and would return an arbitrary one of an administrator's grants. Look-ups
 * name both columns explicitly.
 *
 * Both key columns are `CHARACTER SET ascii` on MySQL, unlike every other
 * address column in the schema, so an internationalised address cannot be
 * stored here at all and a raw join against `domain.domain` raises an
 * illegal-mix-of-collations error (docs/reference/schema-type-matrix.md, D8) —
 * the relations below correlate with binds rather than joining.
 *
 * @property string $username
 * @property string $domain
 * @property ?CarbonImmutable $created
 * @property ?CarbonImmutable $modified
 * @property ?CarbonImmutable $expired
 * @property bool $active
 * @property-read Domain $mailDomain
 * @property-read Mailbox $mailbox
 */
final class DomainAdmin extends MailModel
{
    /**
     * The `domain` value standing for "every domain", rather than a real one.
     *
     * A global admin is represented twice: `mailbox.isglobaladmin = 1` and a
     * row here carrying this sentinel (`docs/features/domain-admins.md` BR-04).
     * It names no row in `domain`, so every query correlating the two tables
     * must exclude it or a global admin's domain list silently returns zero
     * rows (BR-05).
     *
     * **Sourced from iRedMail's own documentation and not verified against a
     * running install** — OQ-DA-09, and OQ-DA-05 for the literal casing. If the
     * verification refutes it, BR-04 and BR-05 both change and so does every
     * read of this constant.
     */
    public const ALL_DOMAINS = 'ALL';

    protected $table = 'domain_admins';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'domain',
        'created',
        'modified',
        'expired',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created' => NeverSetDate::class,
            'modified' => NeverSetDate::class,
            'expired' => NeverExpiresDate::class,
            'active' => IntegerBoolean::class,
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain', 'domain');
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'username', 'username');
    }
}
