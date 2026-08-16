<?php

declare(strict_types=1);

use App\Models\AuditEntry;
use App\Models\Mail\Mailbox;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/audit-log.md, the reading half. BR-16 is the whole
 * authorization story — global admins only, Q3 answered A — and BR-05 is why
 * there is nothing here but a listing.
 */
beforeEach(function () {
    foreach (['forwardings', 'alias', 'alias_domain', 'mailbox', 'domain_admins', 'domain'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }

    AuditEntry::query()->delete();
});

function auditGlobalAdmin(): Mailbox
{
    return new Mailbox(['username' => 'root@example.test', 'isglobaladmin' => true, 'isadmin' => true, 'active' => true]);
}

function auditDomainAdmin(string $domain = 'a.test'): Mailbox
{
    DB::connection('vmail')->table('domain')->insert([
        'domain' => $domain,
        'aliases' => 0, 'mailboxes' => 0, 'maillists' => 0, 'maxquota' => 0,
        'active' => 1,
        'created' => now(), 'modified' => now(),
        'expired' => DB::connection('vmail')->getDriverName() === 'mysql'
            ? '9999-12-31 00:00:00'
            : '9999-12-31 01:01:01',
    ]);

    DB::connection('vmail')->table('domain_admins')->insert([
        'username' => 'da@example.test',
        'domain' => $domain,
        'created' => now(), 'modified' => now(),
        'expired' => DB::connection('vmail')->getDriverName() === 'mysql'
            ? '9999-12-31 00:00:00'
            : '9999-12-31 01:01:01',
        'active' => 1,
    ]);

    return new Mailbox(['username' => 'da@example.test', 'isadmin' => true, 'isglobaladmin' => false, 'active' => true]);
}

/**
 * @param  array<string, mixed>  $attributes
 */
function auditEntry(array $attributes = []): AuditEntry
{
    /** @var AuditEntry $entry */
    $entry = AuditEntry::query()->create([
        'log_name' => 'default',
        'description' => 'updated',
        'event' => 'updated',
        'subject_type' => 'App\Models\Mail\Domain',
        'subject_id' => 'a.test',
        'actor' => 'root@example.test',
        'ip_address' => '203.0.113.7',
        'properties' => ['old' => ['description' => 'before'], 'attributes' => ['description' => 'after']],
        ...$attributes,
    ]);

    return $entry;
}

describe('authorization (BR-14, BR-16)', function () {
    it('lets a global admin read the log', function () {
        auditEntry();

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('AuditLog/Index')
                ->has('entries.data', 1)
        );
    });

    it('refuses a domain admin even for a target inside their own domain (AC-17)', function () {
        // The cost of Q3=A, stated rather than hidden: they cannot see who
        // probed their own domain, and in v1 they ask a global admin instead.
        $actor = auditDomainAdmin('a.test');

        auditEntry(['subject_id' => 'someone@a.test', 'subject_type' => 'App\Models\Mail\Mailbox']);

        $response = $this->actingAs($actor)->get('/audit-log');

        $response->assertForbidden();

        expect($response->getContent())->not->toContain('someone@a.test');
    });

    it('refuses a domain admin even for the entries they produced themselves', function () {
        $actor = auditDomainAdmin('a.test');

        auditEntry(['actor' => 'da@example.test']);

        $this->actingAs($actor)->get('/audit-log')->assertForbidden();
    });

    it('redirects an unauthenticated request to the login form (AC-13)', function () {
        auditEntry();

        $this->get('/audit-log')->assertRedirect('/login');
    });
});

describe('the listing (Contracts)', function () {
    it('returns the newest entry first', function () {
        auditEntry(['description' => 'oldest', 'created_at' => now()->subDays(2)]);
        auditEntry(['description' => 'newest', 'created_at' => now()]);

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('entries.data.0.description', 'newest')
                ->where('entries.data.1.description', 'oldest')
        );
    });

    it('paginates rather than returning the whole log', function () {
        foreach (range(1, 30) as $index) {
            auditEntry(['description' => "entry {$index}"]);
        }

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('entries.data', 25)
                ->where('entries.total', 30)
        );
    });

    it('filters by actor and by event', function () {
        auditEntry(['actor' => 'root@example.test', 'event' => 'created']);
        auditEntry(['actor' => 'other@example.test', 'event' => 'updated']);

        $this->actingAs(auditGlobalAdmin())->get('/audit-log?actor=other%40example.test')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.actor', 'other@example.test')
        );

        $this->actingAs(auditGlobalAdmin())->get('/audit-log?event=created')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('entries.data', 1)
                ->where('entries.data.0.event', 'created')
        );
    });

    it('drops a filter value outside the allowlist before it reaches a query (AC-14)', function () {
        auditEntry(['actor' => 'root@example.test']);

        $this->actingAs(auditGlobalAdmin())
            ->get('/audit-log?actor='.urlencode("' or 1=1 --").'&event='.urlencode('id; drop table audit_log'))
            ->assertOk()
            ->assertInertia(
                fn (AssertableInertia $page) => $page
                    ->where('filters.actor', '')
                    ->where('filters.event', '')
                    ->has('entries.data', 1)
            );
    });

    it('offers only the actors and events the log actually holds', function () {
        auditEntry(['actor' => 'root@example.test', 'event' => 'created']);
        auditEntry(['actor' => 'root@example.test', 'event' => 'deleted']);

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('actors', 1)
                ->has('events', 2)
        );
    });

    it('lists an entry whose target no longer exists (AC-12)', function () {
        // The log deliberately keeps references to accounts that are gone; it
        // is the one table exempt from the orphan cleanup (BR-08).
        auditEntry(['subject_id' => 'deleted@nowhere.test', 'subject_type' => 'App\Models\Mail\Mailbox']);

        expect(DB::connection('vmail')->table('mailbox')->count())->toBe(0);

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('entries.data.0.target.id', 'deleted@nowhere.test')
                ->where('entries.data.0.target.type', 'Mailbox')
        );
    });

    it('lists entries with no domain-scoped target at all (AC-18)', function () {
        auditEntry(['event' => 'signed-in', 'subject_type' => null, 'subject_id' => null]);
        auditEntry(['event' => 'settings-changed', 'subject_type' => null, 'subject_id' => null]);
        auditEntry(['event' => 'created', 'actor' => AuditEntry::CONSOLE_ACTOR, 'ip_address' => 'root@mail01']);

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertInertia(
            fn (AssertableInertia $page) => $page->has('entries.data', 3)
        );
    });

    it('joins no vmail table and opens no cross-database transaction (AC-11)', function () {
        auditEntry();

        $seen = [];

        DB::listen(function ($query) use (&$seen): void {
            $seen[] = [$query->connectionName, mb_strtolower($query->sql)];
        });

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertOk();

        foreach ($seen as [$connection, $sql]) {
            if (str_contains($sql, 'audit_log')) {
                expect($connection)->not->toBe('vmail')
                    ->and($sql)->not->toContain('mailbox')
                    ->and($sql)->not->toContain('used_quota');
            }
        }
    });
});

describe('reading the payload', function () {
    it('renders before and after as field pairs rather than raw JSON', function () {
        auditEntry([
            'properties' => [
                'old' => ['description' => 'before', 'quota' => 1024],
                'attributes' => ['description' => 'after', 'quota' => 2048],
            ],
        ]);

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->has('entries.data.0.changes', 2)
                ->where('entries.data.0.changes.0.field', 'description')
                ->where('entries.data.0.changes.0.from', 'before')
                ->where('entries.data.0.changes.0.to', 'after')
                ->where('entries.data.0.changes.1.field', 'quota')
                ->where('entries.data.0.changes.1.from', '1024')
                ->where('entries.data.0.changes.1.to', '2048')
        );
    });

    it('names the counts a cascade removed, per table (BR-19)', function () {
        auditEntry([
            'event' => 'deleted',
            'properties' => [
                'type' => 'domain',
                'identifier' => 'doomed.test',
                'old' => ['removed' => ['mailboxes' => 2, 'aliases' => 1, 'members' => 2, 'aliasDomains' => 1]],
            ],
            'subject_type' => null,
            'subject_id' => null,
        ]);

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('entries.data.0.target.type', 'domain')
                ->where('entries.data.0.target.id', 'doomed.test')
                ->where('entries.data.0.removed.mailboxes', 2)
                ->where('entries.data.0.removed.aliases', 1)
                ->where('entries.data.0.removed.members', 2)
                ->where('entries.data.0.removed.aliasDomains', 1)
                ->has('entries.data.0.changes', 0)
        );
    });

    it('adds no second redaction layer over what the writer already stripped (BR-04)', function () {
        /*
         * Secrets are stripped at write time, at the one place every write
         * funnels through. Redacting again here would imply that pass is
         * unreliable — and would hide a leak rather than prevent one, because
         * the value would still be in the table. So the reader shows exactly
         * what was recorded, and what was recorded is the marker.
         */
        auditEntry([
            'properties' => [
                'old' => ['password' => '[redacted]'],
                'attributes' => ['password' => '[redacted]'],
            ],
        ]);

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('entries.data.0.changes.0.field', 'password')
                ->where('entries.data.0.changes.0.from', '[redacted]')
                ->where('entries.data.0.changes.0.to', '[redacted]')
        );
    });
});

describe('read-only (BR-05, AC-06)', function () {
    it('resolves no route for updating or deleting an entry', function () {
        $entry = auditEntry();

        foreach ([
            ['put', "/audit-log/{$entry->getKey()}"],
            ['patch', "/audit-log/{$entry->getKey()}"],
            ['delete', "/audit-log/{$entry->getKey()}"],
            ['post', '/audit-log'],
        ] as [$method, $path]) {
            // 404 where no URI matches, 405 where the listing's URI matches but
            // the verb does not. Either way nothing resolves and nothing runs.
            $status = $this->actingAs(auditGlobalAdmin())->{$method}($path)->status();

            expect([404, 405])->toContain($status);
        }

        expect(AuditEntry::query()->count())->toBe(1);
    });

    it('issues no write against audit_log while it is being read', function () {
        auditEntry();

        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        $this->actingAs(auditGlobalAdmin())->get('/audit-log')->assertOk();

        foreach ($statements as $sql) {
            if (str_contains($sql, 'audit_log')) {
                expect($sql)->toStartWith('select');
            }
        }
    });
});
