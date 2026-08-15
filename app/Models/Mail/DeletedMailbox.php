<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Exceptions\ReadOnlyMailTable;
use Illuminate\Support\Carbon;

/**
 * A mailbox queued for storage removal — table `deleted_mailboxes`. Writing a
 * row here is how Mailward deletes mail storage: iRedMail's own cron job does
 * the filesystem work, so no root, no shell and no privileged helper are
 * involved (docs/01-architecture.md §6, docs/02-domain.md §11).
 *
 * **Insert only.** PostgreSQL gives `id` a `SERIAL PRIMARY KEY`; MySQL declares
 * no primary key and no unique index at all, so nothing guarantees `id`
 * identifies one row there (docs/reference/schema-type-matrix.md, D10).
 * `$incrementing` is therefore false — the id the database assigns is not read
 * back — and every update path throws, including `save()` on a row that came
 * from a query. Inserting stays available.
 *
 * `timestamp` is MySQL's time-zone-converting `TIMESTAMP` and PostgreSQL's
 * naive `TIMESTAMP WITHOUT TIME ZONE`, so the same row reads differently
 * through the two drivers; `delete_date` is the column to reason with (D11).
 *
 * @property int $id
 * @property Carbon $timestamp
 * @property string $username
 * @property string $domain
 * @property string $maildir
 * @property int $bytes
 * @property int $messages
 * @property string $admin
 * @property ?Carbon $delete_date
 */
final class DeletedMailbox extends MailModel
{
    private const TABLE = 'deleted_mailboxes';

    protected $table = self::TABLE;

    public $incrementing = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'username',
        'domain',
        'maildir',
        'bytes',
        'messages',
        'admin',
        'delete_date',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'timestamp' => 'datetime',
            'bytes' => 'integer',
            'messages' => 'integer',
            'delete_date' => 'date',
        ];
    }

    public function save(array $options = []): bool
    {
        if ($this->exists) {
            throw ReadOnlyMailTable::insertOnly(self::TABLE);
        }

        return parent::save($options);
    }

    public function update(array $attributes = [], array $options = []): never
    {
        throw ReadOnlyMailTable::insertOnly(self::TABLE);
    }

    public function delete(): never
    {
        throw ReadOnlyMailTable::insertOnly(self::TABLE);
    }
}
