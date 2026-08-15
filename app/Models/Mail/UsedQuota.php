<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Exceptions\ReadOnlyMailTable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Quota *usage* per account — table `used_quota`, keyed by `username`. The
 * quota *limit* is `mailbox.quota`, a different column on a different table.
 *
 * **Read-only.** Both schema files carry the same comment: Dovecot maintains
 * this table and nothing else may touch it (docs/02-domain.md §9). The write
 * paths throw rather than merely documenting it, because a value corrected by
 * hand here is silently overwritten and, until it is, disagrees with what
 * Dovecot enforces.
 *
 * `domain` is filled by a MySQL trigger and by nothing at all on PostgreSQL,
 * so Mailward never reads it: per-domain usage is correlated through
 * `username` against `mailbox` on both drivers
 * (docs/reference/schema-type-matrix.md, D14).
 *
 * @property string $username
 * @property int $bytes
 * @property int $messages
 * @property string $domain
 * @property-read Mailbox $mailbox
 */
final class UsedQuota extends MailModel
{
    private const TABLE = 'used_quota';

    protected $table = self::TABLE;

    protected $primaryKey = 'username';

    protected $keyType = 'string';

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'bytes' => 'integer',
            'messages' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Mailbox, $this>
     */
    public function mailbox(): BelongsTo
    {
        return $this->belongsTo(Mailbox::class, 'username', 'username');
    }

    public function save(array $options = []): never
    {
        throw ReadOnlyMailTable::maintainedByDovecot(self::TABLE);
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw ReadOnlyMailTable::maintainedByDovecot(self::TABLE);
    }

    public function delete(): never
    {
        throw ReadOnlyMailTable::maintainedByDovecot(self::TABLE);
    }

    /**
     * Closes the query-builder passthrough that `Model::__callStatic()` would
     * otherwise forward to `Builder::insert()`, bypassing `save()`.
     *
     * @param  array<string, mixed>  $values
     */
    public static function insert(array $values): never
    {
        throw ReadOnlyMailTable::maintainedByDovecot(self::TABLE);
    }
}
