<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Casts\IntegerBoolean;
use App\Casts\NeverExpiresDate;
use App\Casts\NeverSetDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A mail domain — table `domain`, keyed by the domain name itself.
 *
 * `aliases`, `mailboxes` and `maillists` are per-domain limits where `0` means
 * unlimited rather than "none allowed" (docs/02-domain.md §2). They collide by
 * name with the relations that would return those accounts, so the relations
 * are named `aliasAccounts` and `mailboxAccounts`: an attribute always wins
 * over a relation of the same name in Eloquent, which would have made the
 * relation unreachable as a property.
 *
 * @property string $domain
 * @property ?string $description
 * @property ?string $disclaimer
 * @property int $aliases
 * @property int $mailboxes
 * @property int $maillists
 * @property int $maxquota
 * @property int $quota
 * @property string $transport
 * @property bool $backupmx
 * @property ?string $settings
 * @property ?CarbonImmutable $created
 * @property ?CarbonImmutable $modified
 * @property ?CarbonImmutable $expired
 * @property bool $active
 * @property-read Collection<int, Mailbox> $mailboxAccounts
 * @property-read Collection<int, Alias> $aliasAccounts
 * @property-read Collection<int, AliasDomain> $aliasDomains
 * @property-read Collection<int, DomainAdmin> $admins
 */
final class Domain extends MailModel
{
    protected $table = 'domain';

    /** The domain record scopes on its own primary key. */
    public function domainScopeColumn(): string
    {
        return 'domain';
    }

    protected $primaryKey = 'domain';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * `settings` belongs to iRedAdmin-Pro and `quota` is a historical column
     * iRedMail no longer uses, so neither is writable here
     * (docs/02-domain.md §1.4 and §2).
     *
     * @var list<string>
     */
    protected $fillable = [
        'domain',
        'description',
        'disclaimer',
        'aliases',
        'mailboxes',
        'maillists',
        'maxquota',
        'transport',
        'backupmx',
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
            'aliases' => 'integer',
            'mailboxes' => 'integer',
            'maillists' => 'integer',
            'maxquota' => 'integer',
            'quota' => 'integer',
            'backupmx' => IntegerBoolean::class,
            'created' => NeverSetDate::class,
            'modified' => NeverSetDate::class,
            'expired' => NeverExpiresDate::class,
            'active' => IntegerBoolean::class,
        ];
    }

    /**
     * @return HasMany<Mailbox, $this>
     */
    public function mailboxAccounts(): HasMany
    {
        return $this->hasMany(Mailbox::class, 'domain', 'domain');
    }

    /**
     * @return HasMany<Alias, $this>
     */
    public function aliasAccounts(): HasMany
    {
        return $this->hasMany(Alias::class, 'domain', 'domain');
    }

    /**
     * @return HasMany<AliasDomain, $this>
     */
    public function aliasDomains(): HasMany
    {
        return $this->hasMany(AliasDomain::class, 'target_domain', 'domain');
    }

    /**
     * @return HasMany<DomainAdmin, $this>
     */
    public function admins(): HasMany
    {
        return $this->hasMany(DomainAdmin::class, 'domain', 'domain');
    }
}
