<?php

declare(strict_types=1);

use App\Models\Mail\Mailbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/dashboard.md. The scoping rules are BR-01 and BR-02, the
 * counting rule is BR-19, the dormancy rule is BR-20, and BR-03 is the one
 * that silently produces wrong numbers if it is got wrong.
 *
 * The suite runs against real MySQL and real PostgreSQL — `composer test`
 * runs both — because the divergences between the two schema files iRedMail
 * ships are exactly what would otherwise ship broken. Every assertion here is
 * therefore a two-driver assertion, and the fixtures are built so that the
 * MySQL-only trigger on `used_quota.domain` cannot mask a query that groups on
 * that column.
 */
beforeEach(function () {
    foreach (['forwardings', 'alias', 'alias_domain', 'mailbox', 'domain_admins', 'domain', 'used_quota', 'last_login'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }
});

/** The sentinel is written per driver, never compared against. */
function dashNeverExpires(): string
{
    return DB::connection('vmail')->getDriverName() === 'mysql'
        ? '9999-12-31 00:00:00'
        : '9999-12-31 01:01:01';
}

function dashDomain(string $name, array $attributes = []): void
{
    DB::connection('vmail')->table('domain')->insert([
        'domain' => $name,
        'aliases' => 0,
        'mailboxes' => 0,
        'maillists' => 0,
        'maxquota' => 0,
        'active' => 1,
        'created' => now(),
        'modified' => now(),
        'expired' => dashNeverExpires(),
        ...$attributes,
    ]);
}

function dashMailbox(string $address, array $attributes = []): void
{
    DB::connection('vmail')->table('mailbox')->insert([
        'username' => $address,
        'domain' => Str::after($address, '@'),
        'password' => '{PLAIN}x',
        'quota' => 1024,
        'active' => 1,
        'created' => now(),
        'modified' => now(),
        'expired' => dashNeverExpires(),
        ...$attributes,
    ]);
}

function dashAlias(string $address, array $attributes = []): void
{
    DB::connection('vmail')->table('alias')->insert([
        'address' => $address,
        'domain' => Str::after($address, '@'),
        'active' => 1,
        'created' => now(),
        'modified' => now(),
        'expired' => dashNeverExpires(),
        ...$attributes,
    ]);
}

/**
 * `domain` is written as the empty string on purpose, on both drivers.
 *
 * On MySQL the `used_quota_before_insert` trigger overwrites it with the part
 * of `username` after the last `@`; on PostgreSQL no trigger exists and the
 * empty string survives. That is D14 reproduced rather than described: a query
 * grouping on this column returns correct per-domain totals on MySQL and a
 * single empty-string bucket on PostgreSQL, so the same expected totals cannot
 * pass on both drivers unless the correlation goes through `username` (BR-03).
 */
function dashUsage(string $address, int $bytes): void
{
    DB::connection('vmail')->table('used_quota')->insert([
        'username' => $address,
        'bytes' => $bytes,
        'messages' => 1,
        'domain' => '',
    ]);
}

function dashLastLogin(string $address, ?int $imap = null, ?int $pop3 = null, ?int $lda = null): void
{
    DB::connection('vmail')->table('last_login')->insert([
        'username' => $address,
        'domain' => Str::after($address, '@'),
        'imap' => $imap,
        'pop3' => $pop3,
        'lda' => $lda,
    ]);
}

function dashGlobalAdmin(): Mailbox
{
    return new Mailbox(['username' => 'root@example.test', 'isglobaladmin' => true, 'isadmin' => true, 'active' => true]);
}

function dashDomainAdmin(string ...$domains): Mailbox
{
    foreach ($domains as $domain) {
        DB::connection('vmail')->table('domain_admins')->insert([
            'username' => 'da@example.test',
            'domain' => $domain,
            'created' => now(),
            'modified' => now(),
            'expired' => dashNeverExpires(),
            'active' => 1,
        ]);
    }

    return new Mailbox(['username' => 'da@example.test', 'isadmin' => true, 'isglobaladmin' => false, 'active' => true]);
}

describe('scope (BR-01, BR-02)', function () {
    it('aggregates every domain for a global admin', function () {
        dashDomain('a.test');
        dashDomain('b.test');
        dashMailbox('one@a.test');
        dashMailbox('two@b.test');

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('Dashboard')
                ->where('figures.domains.total', 2)
                ->where('figures.mailboxes.total', 2)
                ->where('can.viewAuditLog', true)
        );
    });

    it('aggregates only their own domains for a domain admin', function () {
        dashDomain('mine.test');
        dashDomain('theirs.test');
        dashMailbox('one@mine.test');
        dashMailbox('two@theirs.test');
        dashMailbox('three@theirs.test');

        $this->actingAs(dashDomainAdmin('mine.test'))->get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.domains.total', 1)
                ->where('figures.mailboxes.total', 1)
                ->where('figures.perDomain.0.domain', 'mine.test')
                ->count('figures.perDomain', 1)
                ->where('can.viewAuditLog', false)
        );
    });

    it('never lists another domain among the domains at their limit (AC-10)', function () {
        dashDomain('mine.test', ['mailboxes' => 5]);
        dashDomain('theirs.test', ['mailboxes' => 1]);
        dashMailbox('one@theirs.test');

        $this->actingAs(dashDomainAdmin('mine.test'))->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->count('figures.atLimit', 0)
        );
    });

    it('gives an administrator with no domains an empty dashboard, not an error (AC-11)', function () {
        dashDomain('other.test');
        dashMailbox('one@other.test');

        $nobody = new Mailbox(['username' => 'nobody@example.test', 'isadmin' => true, 'isglobaladmin' => false, 'active' => true]);

        $this->actingAs($nobody)->get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.domains.total', 0)
                ->where('figures.mailboxes.total', 0)
                ->where('figures.quota.allocatedMib', 0)
                ->where('figures.quota.usedBytes', 0)
        );
    });

    it('redirects an unauthenticated request to the login form (AC-19)', function () {
        $this->get('/dashboard')->assertRedirect('/login');
    });
});

describe('quota totals (BR-03, BR-05, matrix D14)', function () {
    /*
     * The assertion that matters most on this screen, and the one that has to
     * hold on **both** drivers rather than on the one that happened to run.
     *
     * Every fixture row is written with `used_quota.domain = ''`. MySQL's
     * trigger then fills it and PostgreSQL leaves it empty, which is the whole
     * of D14 — and it means one single set of expected totals cannot pass on
     * both drivers unless the correlation runs through `username`. Grouping on
     * that column instead gives correct numbers on MySQL and a zero
     * per-domain total on PostgreSQL: no error, just a wrong figure.
     * `composer test` runs this file against MySQL and PostgreSQL in turn, so
     * the assertion below is made twice, once per driver.
     */
    it('totals usage per domain by correlating on username, on either driver (AC-03, AC-04)', function () {
        dashDomain('a.test');
        dashDomain('b.test');
        dashMailbox('one@a.test', ['quota' => 1024]);
        dashMailbox('two@a.test', ['quota' => 2048]);
        dashMailbox('three@b.test', ['quota' => 512]);

        dashUsage('one@a.test', 1_048_576);
        dashUsage('two@a.test', 2_097_152);
        dashUsage('three@b.test', 4_194_304);

        /*
         * Proof the hazard is present rather than assumed, on whichever driver
         * is running: MySQL's `used_quota_before_insert` trigger overwrites the
         * empty string the fixture wrote, PostgreSQL has no trigger at all and
         * leaves it. The very same expected totals hold below on both, because
         * nothing reads that column (matrix D14).
         */
        expect(DB::connection('vmail')->table('used_quota')->where('domain', '')->count())
            ->toBe(DB::connection('vmail')->getDriverName() === 'mysql' ? 0 : 3);

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.perDomain.0.domain', 'a.test')
                ->where('figures.perDomain.0.allocatedMib', 3072)
                ->where('figures.perDomain.0.usedBytes', 3_145_728)
                ->where('figures.perDomain.1.domain', 'b.test')
                ->where('figures.perDomain.1.allocatedMib', 512)
                ->where('figures.perDomain.1.usedBytes', 4_194_304)
                ->where('figures.quota.allocatedMib', 3584)
                ->where('figures.quota.usedBytes', 7_340_032)
        );

        // And the column that would have produced one of those two answers is
        // named by no executed statement, on either driver.
        foreach ($statements as $sql) {
            expect($sql)->not->toContain('used_quota"."domain')
                ->and($sql)->not->toContain('used_quota`.`domain');
        }
    });

    it('still counts a mailbox that has no used_quota row (AC-06)', function () {
        dashDomain('a.test');
        dashMailbox('one@a.test', ['quota' => 1024]);
        dashMailbox('two@a.test', ['quota' => 1024]);
        dashUsage('one@a.test', 1_048_576);

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.mailboxes.total', 2)
                ->where('figures.perDomain.0.mailboxes', 2)
                ->where('figures.perDomain.0.usedBytes', 1_048_576)
        );
    });

    it('surfaces no domain.quota value anywhere in the props (AC-09)', function () {
        // `domain.quota` is a historical column iRedMail no longer uses. A
        // value nothing else could produce proves it reached no prop.
        dashDomain('a.test', ['quota' => 987654321]);
        dashMailbox('one@a.test');

        $response = $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertOk();

        expect($response->getContent())->not->toContain('987654321');
    });

    it('issues no write on any connection (AC-05)', function () {
        dashDomain('a.test');
        dashMailbox('one@a.test');

        $statements = [];

        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertOk();

        expect($statements)->not->toBeEmpty();

        foreach ($statements as $sql) {
            expect($sql)->toStartWith('select')
                ->and($sql)->not->toContain('used_quota"."domain')
                ->and($sql)->not->toContain('used_quota`.`domain');
        }
    });
});

describe('counts include every row, with an inactive figure beside them (BR-19)', function () {
    it('counts five mailboxes of which three are not live (AC-21, AC-22)', function () {
        dashDomain('a.test');
        dashMailbox('live@a.test');
        dashMailbox('also-live@a.test');
        dashMailbox('off@a.test', ['active' => 0]);
        dashMailbox('gone@a.test', ['expired' => now()->subDay()]);
        // Both at once contributes 1 to the breakdown, not 2 (AC-22).
        dashMailbox('off-and-gone@a.test', ['active' => 0, 'expired' => now()->subDay()]);

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.mailboxes.total', 5)
                ->where('figures.mailboxes.inactive', 3)
        );
    });

    it('counts a disabled domain in the headline and keeps its mailboxes (AC-23)', function () {
        dashDomain('live.test');
        dashDomain('off.test', ['active' => 0]);
        dashDomain('gone.test', ['expired' => now()->subDay()]);
        dashMailbox('one@off.test');

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.domains.total', 3)
                ->where('figures.domains.inactive', 2)
                ->where('figures.mailboxes.total', 1)
        );
    });

    it('treats the never-expires sentinel as not expired on both drivers (AC-15)', function () {
        dashDomain('a.test');
        dashMailbox('one@a.test', ['expired' => dashNeverExpires()]);

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.mailboxes.total', 1)
                ->where('figures.mailboxes.inactive', 0)
        );
    });
});

describe('domains at their limit (BR-07, BR-08, BR-18)', function () {
    it('treats a zero limit as unlimited (AC-07)', function () {
        dashDomain('a.test', ['mailboxes' => 0]);
        dashMailbox('one@a.test');
        dashMailbox('two@a.test');

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page->count('figures.atLimit', 0)
        );
    });

    it('reports a domain at the limit and not one below it (AC-08)', function () {
        dashDomain('full.test', ['mailboxes' => 2]);
        dashDomain('room.test', ['mailboxes' => 2]);
        dashMailbox('one@full.test');
        dashMailbox('two@full.test');
        dashMailbox('one@room.test');

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->count('figures.atLimit', 1)
                ->where('figures.atLimit.0.domain', 'full.test')
                ->where('figures.atLimit.0.reasons.0', 'mailboxes')
        );
    });

    it('counts standalone alias rows only against the alias limit (AC-20)', function () {
        dashDomain('a.test', ['aliases' => 2]);
        dashAlias('sales@a.test');
        dashAlias('support@a.test');
        dashMailbox('one@a.test');

        // Neither of these is bounded by domain.aliases, so neither may count.
        foreach (['x1', 'x2', 'x3', 'x4', 'x5', 'x6'] as $name) {
            DB::connection('vmail')->table('forwardings')->insert([
                'address' => $name.'@a.test',
                'forwarding' => 'one@a.test',
                'domain' => 'a.test',
                'dest_domain' => 'a.test',
                'is_alias' => 1,
                'active' => 1,
            ]);
        }

        foreach (['b.test', 'c.test', 'd.test'] as $name) {
            DB::connection('vmail')->table('alias_domain')->insert([
                'alias_domain' => $name,
                'target_domain' => 'a.test',
                'active' => 1,
            ]);
        }

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->count('figures.atLimit', 1)
                ->where('figures.atLimit.0.aliases.count', 2)
                ->where('figures.atLimit.0.reasons.0', 'aliases')
        );

        foreach ($statements as $sql) {
            expect($sql)->not->toContain('forwardings')
                ->and($sql)->not->toContain('alias_domain');
        }
    });
});

describe('dormancy (BR-20, BR-12, BR-13)', function () {
    it('uses the greater of imap and pop3 and ignores lda entirely (AC-25, AC-26)', function () {
        dashDomain('a.test');

        // lda today, imap and pop3 two years old: dormant despite the delivery.
        dashMailbox('lda@a.test');
        dashLastLogin(
            'lda@a.test',
            imap: now()->subYears(2)->getTimestamp(),
            pop3: now()->subYears(2)->getTimestamp(),
            lda: now()->getTimestamp(),
        );

        // imap 200 days old, pop3 10 days old: the greater decides, so not dormant.
        dashMailbox('recent@a.test');
        dashLastLogin(
            'recent@a.test',
            imap: now()->subDays(200)->getTimestamp(),
            pop3: now()->subDays(10)->getTimestamp(),
        );

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.dormancy.dormant', 1)
                ->where('figures.dormancy.neverLoggedIn', 0)
                ->where('figures.dormancy.unknown', 0)
                ->where('figures.dormancy.thresholdDays', 90)
        );
    });

    it('lets the reliable column decide when the other has overflowed (AC-12, AC-27)', function () {
        dashDomain('a.test');

        dashMailbox('one@a.test');
        dashLastLogin('one@a.test', imap: -1, pop3: now()->subDays(200)->getTimestamp());

        dashMailbox('two@a.test');
        dashLastLogin('two@a.test', imap: -1, pop3: now()->addYears(5)->getTimestamp());

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.dormancy.dormant', 1)
                ->where('figures.dormancy.unknown', 1)
                ->where('figures.dormancy.neverLoggedIn', 0)
                // Not computable is not zero: the figure degrades and says so.
                ->where('figures.dormancy.degraded', true)
        );
    });

    it('reports never logged in distinguishably from unknown (AC-13, AC-28)', function () {
        dashDomain('a.test');

        // No last_login row at all.
        dashMailbox('none@a.test');

        // A row whose columns are both NULL.
        dashMailbox('null@a.test');
        dashLastLogin('null@a.test');

        // A row whose only value is unreliable.
        dashMailbox('broken@a.test');
        dashLastLogin('broken@a.test', imap: 0);

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.dormancy.neverLoggedIn', 2)
                ->where('figures.dormancy.unknown', 1)
                ->where('figures.dormancy.dormant', 0)
        );
    });

    it('states the configured threshold and honours it (AC-29, AC-30)', function () {
        dashDomain('a.test');
        dashMailbox('one@a.test');
        dashLastLogin('one@a.test', imap: now()->subDays(60)->getTimestamp());

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.dormancy.thresholdDays', 90)
                ->where('figures.dormancy.dormant', 0)
        );

        config()->set('mailward.dashboard.dormant_after_days', 30);

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertInertia(
            fn (AssertableInertia $page) => $page
                ->where('figures.dormancy.thresholdDays', 30)
                ->where('figures.dormancy.dormant', 1)
        );
    });

    it('never reads last_login through find(), and never selects lda (BR-11, BR-20)', function () {
        dashDomain('a.test');
        dashMailbox('one@a.test');
        dashLastLogin('one@a.test', imap: now()->getTimestamp(), lda: now()->getTimestamp());

        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = mb_strtolower($query->sql);
        });

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertOk();

        $lastLogin = array_values(array_filter(
            $statements,
            fn (string $sql): bool => str_contains($sql, 'last_login'),
        ));

        expect($lastLogin)->not->toBeEmpty();

        foreach ($lastLogin as $sql) {
            expect($sql)->toContain('username')
                ->and($sql)->not->toContain('lda');
        }
    });
});

describe('aggregates, not hydrated collections (BR-09, BR-10)', function () {
    it('does not grow the number of statements with the number of mailboxes (AC-16)', function () {
        dashDomain('a.test');

        foreach (range(1, 3) as $index) {
            dashMailbox("small{$index}@a.test");
        }

        $statements = 0;

        DB::listen(function () use (&$statements): void {
            $statements++;
        });

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertOk();

        $small = $statements;

        foreach (range(4, 30) as $index) {
            dashMailbox("big{$index}@a.test");
        }

        $statements = 0;

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertOk();

        // Tenfold the rows, the same number of statements: the figures are
        // aggregates, and no collection is hydrated in order to be counted.
        expect($statements)->toBe($small);
    });

    it('joins no Mailward table to a vmail table (AC-17)', function () {
        dashDomain('a.test');
        dashMailbox('one@a.test');

        $seen = [];

        DB::listen(function ($query) use (&$seen): void {
            $seen[] = [$query->connectionName, mb_strtolower($query->sql)];
        });

        $this->actingAs(dashGlobalAdmin())->get('/dashboard')->assertOk();

        $mailwardTables = ['audit_log', 'panel_profiles', 'sessions'];
        $vmailTables = ['mailbox', 'used_quota', 'last_login', 'domain_admins'];

        foreach ($seen as [$connection, $sql]) {
            $foreign = $connection === 'vmail' ? $mailwardTables : $vmailTables;

            foreach ($foreign as $table) {
                expect($sql)->not->toContain($table);
            }
        }
    });
});
