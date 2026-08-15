<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Casts\IntegerBoolean;
use App\Casts\NeverExpiresDate;
use App\Casts\NeverSetDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A standalone alias account — table `alias`, keyed by `address`. It only
 * redirects; its members live in `forwardings` with `is_list` set
 * (docs/02-domain.md §6).
 *
 * The relation to the domain is named `mailDomain` because `domain` is also a
 * column here, and an attribute of the same name would shadow the relation.
 *
 * @property string $address
 * @property string $name
 * @property string $accesspolicy
 * @property string $domain
 * @property ?CarbonImmutable $created
 * @property ?CarbonImmutable $modified
 * @property ?CarbonImmutable $expired
 * @property bool $active
 * @property-read Collection<int, Forwarding> $members
 * @property-read Domain $mailDomain
 */
final class Alias extends MailModel
{
    protected $table = 'alias';

    protected $primaryKey = 'address';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'address',
        'name',
        'accesspolicy',
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
     * The flag is bound as `1` rather than `true`: the column is INT2 on
     * PostgreSQL, which has no implicit cast from boolean
     * (docs/reference/schema-type-matrix.md, D4).
     *
     * @return HasMany<Forwarding, $this>
     */
    public function members(): HasMany
    {
        return $this->hasMany(Forwarding::class, 'address', 'address')
            ->where('is_list', 1);
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function mailDomain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'domain', 'domain');
    }
}
