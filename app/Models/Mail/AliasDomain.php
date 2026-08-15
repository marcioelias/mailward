<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Casts\IntegerBoolean;
use App\Casts\NeverSetDate;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain whose mail is delivered to the accounts of another domain — table
 * `alias_domain`, keyed by `alias_domain`.
 *
 * Alone among the tables Mailward models, this one has no `expired` column in
 * either schema file (docs/reference/schema-type-matrix.md §2). Nothing
 * enforces that `target_domain` exists in `domain`; that is a business rule
 * Mailward checks before writing (docs/02-domain.md §3).
 *
 * @property string $alias_domain
 * @property string $target_domain
 * @property ?CarbonImmutable $created
 * @property ?CarbonImmutable $modified
 * @property bool $active
 * @property-read Domain $targetDomain
 */
final class AliasDomain extends MailModel
{
    protected $table = 'alias_domain';

    protected $primaryKey = 'alias_domain';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'alias_domain',
        'target_domain',
        'created',
        'modified',
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
            'active' => IntegerBoolean::class,
        ];
    }

    /**
     * @return BelongsTo<Domain, $this>
     */
    public function targetDomain(): BelongsTo
    {
        return $this->belongsTo(Domain::class, 'target_domain', 'domain');
    }
}
