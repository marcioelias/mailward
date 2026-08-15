<?php

declare(strict_types=1);

use App\Exceptions\ReadOnlyMailTable;
use App\Models\Mail\DeletedMailbox;
use App\Models\Mail\LastLogin;
use App\Models\Mail\UsedQuota;

/**
 * The iRedMail schema states these constraints in comments only — the database
 * accepts every one of these writes, and the damage is silent. See
 * docs/02-domain.md §9, §10 and §11.
 */
describe('used_quota and last_login are maintained by Dovecot', function () {
    it('refuses to save', function () {
        expect(fn () => (new UsedQuota)->save())->toThrow(ReadOnlyMailTable::class, 'used_quota')
            ->and(fn () => (new LastLogin)->save())->toThrow(ReadOnlyMailTable::class, 'last_login');
    });

    it('refuses to update', function () {
        expect(fn () => (new UsedQuota)->update(['bytes' => 0]))->toThrow(ReadOnlyMailTable::class)
            ->and(fn () => (new LastLogin)->update(['imap' => 0]))->toThrow(ReadOnlyMailTable::class);
    });

    it('refuses to delete', function () {
        expect(fn () => (new UsedQuota)->delete())->toThrow(ReadOnlyMailTable::class)
            ->and(fn () => (new LastLogin)->delete())->toThrow(ReadOnlyMailTable::class);
    });

    it('refuses the query builder insert passthrough', function () {
        expect(fn () => UsedQuota::insert(['username' => 'user@example.test']))->toThrow(ReadOnlyMailTable::class)
            ->and(fn () => LastLogin::insert(['username' => 'user@example.test']))->toThrow(ReadOnlyMailTable::class);
    });
});

describe('deleted_mailboxes is insert only (D10 — MySQL has no unique key)', function () {
    it('refuses to update or delete', function () {
        $row = new DeletedMailbox(['username' => 'user@example.test']);

        expect(fn () => $row->update(['bytes' => 0]))->toThrow(ReadOnlyMailTable::class, 'deleted_mailboxes')
            ->and(fn () => $row->delete())->toThrow(ReadOnlyMailTable::class);
    });

    it('refuses to save a row that came from the database', function () {
        $row = new DeletedMailbox(['username' => 'user@example.test']);
        $row->exists = true;

        expect(fn () => $row->save())->toThrow(ReadOnlyMailTable::class);
    });
});
