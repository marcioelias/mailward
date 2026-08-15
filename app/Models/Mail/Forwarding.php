<?php

declare(strict_types=1);

namespace App\Models\Mail;

use App\Casts\IntegerBoolean;

/**
 * A row of `forwardings` — the only table in the schema with a plural,
 * irregular name, and the only one that multiplexes four unrelated concepts
 * behind flag columns (docs/02-domain.md §5):
 *
 * | Flag | Meaning |
 * |---|---|
 * | `is_forwarding` | a mail user's forwarding address |
 * | `is_alias` | a per-account alias address |
 * | `is_list` | membership of a standalone alias account |
 * | `is_maillist` | membership of an mlmmj mailing list |
 *
 * `address` therefore points at a mailbox, an alias account or a mailing list
 * depending on which flag is set, so no single `belongsTo` on it would be
 * correct; the owning side declares the relation instead.
 *
 * Every mailbox must have a row here pointing at itself with
 * `address = forwarding` and `is_forwarding` set, or it silently stops
 * receiving mail. MySQL has no index on `is_forwarding`, so queries are
 * anchored on `address` or `domain` and never on the flag alone
 * (docs/reference/schema-type-matrix.md, D15).
 *
 * @property int $id
 * @property string $address
 * @property string $forwarding
 * @property string $domain
 * @property string $dest_domain
 * @property bool $is_maillist
 * @property bool $is_list
 * @property bool $is_forwarding
 * @property bool $is_alias
 * @property bool $active
 */
final class Forwarding extends MailModel
{
    protected $table = 'forwardings';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'address',
        'forwarding',
        'domain',
        'dest_domain',
        'is_maillist',
        'is_list',
        'is_forwarding',
        'is_alias',
        'active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'id' => 'integer',
            'is_maillist' => IntegerBoolean::class,
            'is_list' => IntegerBoolean::class,
            'is_forwarding' => IntegerBoolean::class,
            'is_alias' => IntegerBoolean::class,
            'active' => IntegerBoolean::class,
        ];
    }
}
