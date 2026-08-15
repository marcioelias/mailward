<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Casts\IntegerBoolean;
use App\Casts\NeverExpiresDate;
use App\Casts\NeverSetDate;
use App\Casts\YesNoBoolean;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Authenticatable;
use Illuminate\Contracts\Auth\Authenticatable as AuthenticatableContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A mail account — table `mailbox`, keyed by the full address in `username`.
 *
 * `password` is the account's real mail password: it authenticates IMAP, SMTP
 * and webmail, not only the panel (docs/02-domain.md §4). It is in `$hidden`
 * so it cannot reach a response by accident.
 *
 * Three of the 57 columns cannot be declared as `@property` at all —
 * `enablelib-storage`, `enablequota-status` and `enableindexer-worker` are
 * hyphenated, which is not a legal PHP property name. They are cast and
 * fillable like every other flag, and are read and written through
 * `getAttribute()` / `setAttribute()` (docs/reference/schema-type-matrix.md,
 * D19).
 *
 * The relation to the domain is named `mailDomain` because `domain` is also a
 * column here, and an attribute of the same name would shadow the relation.
 *
 * @property string $username
 * @property string $password
 * @property string $name
 * @property string $language
 * @property string $first_name
 * @property string $last_name
 * @property string $mobile
 * @property string $telephone
 * @property string $recovery_email
 * @property ?Carbon $birthday
 * @property string $mailboxformat
 * @property string $mailboxfolder
 * @property string $storagebasedirectory
 * @property string $storagenode
 * @property string $maildir
 * @property int $quota
 * @property string $domain
 * @property string $transport
 * @property string $department
 * @property string $rank
 * @property string $employeeid
 * @property bool $isadmin
 * @property bool $isglobaladmin
 * @property bool $enablesmtp
 * @property bool $enablesmtpsecured
 * @property bool $enablepop3
 * @property bool $enablepop3secured
 * @property bool $enablepop3tls
 * @property bool $enableimap
 * @property bool $enableimapsecured
 * @property bool $enableimaptls
 * @property bool $enabledeliver
 * @property bool $enablelda
 * @property bool $enablemanagesieve
 * @property bool $enablemanagesievesecured
 * @property bool $enablesieve
 * @property bool $enablesievesecured
 * @property bool $enablesievetls
 * @property bool $enableinternal
 * @property bool $enabledoveadm
 * @property bool $enablelmtp
 * @property bool $enabledsync
 * @property bool $enablesogo
 * @property bool $enablesogowebmail
 * @property bool $enablesogocalendar
 * @property bool $enablesogoactivesync
 * @property ?string $allow_nets
 * @property ?string $disclaimer
 * @property ?string $settings
 * @property ?CarbonImmutable $passwordlastchange
 * @property ?CarbonImmutable $created
 * @property ?CarbonImmutable $modified
 * @property ?CarbonImmutable $expired
 * @property bool $active
 * @property-read Domain $mailDomain
 * @property-read ?UsedQuota $usedQuota
 * @property-read ?LastLogin $lastLogin
 * @property-read Collection<int, Forwarding> $forwardings
 */
final class Mailbox extends MailModel implements AuthenticatableContract
{
    use Authenticatable;

    protected $table = 'mailbox';

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * There is no `remember_token` column, and there never will be: Mailward
     * issues no DDL against the iRedMail schema
     * (docs/decisions/0002-separate-application-database.md). Returning an
     * empty name makes the framework's remember mechanism inert instead of
     * having it write to a column that does not exist.
     *
     * Persistent sessions are meant to live in Mailward's own database keyed
     * by address (docs/01-architecture.md §5). That mechanism is not designed
     * yet, so the login form does not offer the option.
     *
     * Overridden as a method rather than by redeclaring the trait's property,
     * which PHP rejects as an incompatible composition.
     */
    public function getRememberTokenName(): string
    {
        return '';
    }

    /**
     * `settings` belongs to iRedAdmin-Pro and Mailward never writes it
     * (docs/02-domain.md §1.4). `allow_nets` must be written as NULL rather
     * than an empty string when the account is unrestricted.
     *
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'password',
        'name',
        'language',
        'first_name',
        'last_name',
        'mobile',
        'telephone',
        'recovery_email',
        'birthday',
        'mailboxformat',
        'mailboxfolder',
        'storagebasedirectory',
        'storagenode',
        'maildir',
        'quota',
        'domain',
        'transport',
        'department',
        'rank',
        'employeeid',
        'isadmin',
        'isglobaladmin',
        'enablesmtp',
        'enablesmtpsecured',
        'enablepop3',
        'enablepop3secured',
        'enablepop3tls',
        'enableimap',
        'enableimapsecured',
        'enableimaptls',
        'enabledeliver',
        'enablelda',
        'enablemanagesieve',
        'enablemanagesievesecured',
        'enablesieve',
        'enablesievesecured',
        'enablesievetls',
        'enableinternal',
        'enabledoveadm',
        'enablelib-storage',
        'enablequota-status',
        'enableindexer-worker',
        'enablelmtp',
        'enabledsync',
        'enablesogo',
        'enablesogowebmail',
        'enablesogocalendar',
        'enablesogoactivesync',
        'allow_nets',
        'disclaimer',
        'passwordlastchange',
        'created',
        'modified',
        'expired',
        'active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'birthday' => 'date',
            'quota' => 'integer',
            'isadmin' => IntegerBoolean::class,
            'isglobaladmin' => IntegerBoolean::class,
            'enablesmtp' => IntegerBoolean::class,
            'enablesmtpsecured' => IntegerBoolean::class,
            'enablepop3' => IntegerBoolean::class,
            'enablepop3secured' => IntegerBoolean::class,
            'enablepop3tls' => IntegerBoolean::class,
            'enableimap' => IntegerBoolean::class,
            'enableimapsecured' => IntegerBoolean::class,
            'enableimaptls' => IntegerBoolean::class,
            'enabledeliver' => IntegerBoolean::class,
            'enablelda' => IntegerBoolean::class,
            'enablemanagesieve' => IntegerBoolean::class,
            'enablemanagesievesecured' => IntegerBoolean::class,
            'enablesieve' => IntegerBoolean::class,
            'enablesievesecured' => IntegerBoolean::class,
            'enablesievetls' => IntegerBoolean::class,
            'enableinternal' => IntegerBoolean::class,
            'enabledoveadm' => IntegerBoolean::class,
            'enablelib-storage' => IntegerBoolean::class,
            'enablequota-status' => IntegerBoolean::class,
            'enableindexer-worker' => IntegerBoolean::class,
            'enablelmtp' => IntegerBoolean::class,
            'enabledsync' => IntegerBoolean::class,
            'enablesogo' => IntegerBoolean::class,
            'enablesogowebmail' => YesNoBoolean::class,
            'enablesogocalendar' => YesNoBoolean::class,
            'enablesogoactivesync' => YesNoBoolean::class,
            'passwordlastchange' => NeverSetDate::class,
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
     * @return HasOne<UsedQuota, $this>
     */
    public function usedQuota(): HasOne
    {
        return $this->hasOne(UsedQuota::class, 'username', 'username');
    }

    /**
     * @return HasOne<LastLogin, $this>
     */
    public function lastLogin(): HasOne
    {
        return $this->hasOne(LastLogin::class, 'username', 'username');
    }

    /**
     * Every row in `forwardings` whose `address` is this account, of any of the
     * four kinds the table multiplexes (docs/02-domain.md §5). The mandatory
     * self-forwarding row is one of them.
     *
     * @return HasMany<Forwarding, $this>
     */
    public function forwardings(): HasMany
    {
        return $this->hasMany(Forwarding::class, 'address', 'username');
    }
}
