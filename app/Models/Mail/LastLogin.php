<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Exceptions\ReadOnlyMailTable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last time an account authenticated over each protocol — table
 * `last_login`, maintained by Dovecot (docs/02-domain.md §10).
 *
 * **Read-only**, like `used_quota`: Mailward reads it for the dashboard and
 * for dormant-account reports, and never writes it.
 *
 * **`find()` is not safe here.** The primary key is `(username)` on MySQL and
 * `(username, domain)` on PostgreSQL, and Eloquent cannot model a composite
 * key (docs/reference/schema-type-matrix.md, D12). `$primaryKey` names
 * `username` so the model has a usable identity on MySQL, but on PostgreSQL
 * that column is not unique on its own — every look-up goes through an
 * explicit `where('username', …)`, never `find()` or route model binding.
 *
 * `imap`, `pop3` and `lda` are Unix timestamps in a 32-bit `INT` on MySQL, so
 * they overflow in January 2038; a report reading them must tolerate a wrapped
 * or negative value rather than trusting it (D13).
 *
 * @property string $username
 * @property string $domain
 * @property ?int $imap
 * @property ?int $pop3
 * @property ?int $lda
 * @property-read Mailbox $mailbox
 */
final class LastLogin extends MailModel
{
    private const TABLE = 'last_login';

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
            'imap' => 'integer',
            'pop3' => 'integer',
            'lda' => 'integer',
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
