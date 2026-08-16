<?php

declare(strict_types=1);

use App\Models\Mail\AliasDomain;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;

/**
 * docs/features/alias-domains.md. Writes are global-admin only (BR-12) and
 * visibility resolves through `target_domain` (BR-05).
 */
beforeEach(function () {
    foreach (['alias_domain', 'domain_admins', 'domain'] as $table) {
        DB::connection('vmail')->table($table)->delete();
    }
});

function makeAliasDomain(string $alias, string $target, bool $active = true): void
{
    DB::connection('vmail')->table('alias_domain')->insert([
        'alias_domain' => $alias,
        'target_domain' => $target,
        'created' => now(),
        'modified' => now(),
        'active' => $active ? 1 : 0,
    ]);
}

describe('visibility follows the target domain (BR-05)', function () {
    it('shows every alias domain to a global admin', function () {
        makeDomain('one.test');
        makeDomain('two.test');
        makeAliasDomain('alias-one.test', 'one.test');
        makeAliasDomain('alias-two.test', 'two.test');

        $this->actingAs(globalAdmin())->get('/alias-domains')->assertOk()->assertInertia(
            fn (AssertableInertia $page) => $page->component('AliasDomains/Index')
                ->has('aliasDomains.data', 2)
        );
    });

    it('shows a domain admin only those pointing at a domain they administer', function () {
        makeDomain('mine.test');
        makeDomain('theirs.test');
        makeAliasDomain('alias-mine.test', 'mine.test');
        makeAliasDomain('alias-theirs.test', 'theirs.test');

        $this->actingAs(domainAdmin('mine.test'))->get('/alias-domains')->assertInertia(
            fn (AssertableInertia $page) => $page->has('aliasDomains.data', 1)
                ->where('aliasDomains.data.0.alias_domain', 'alias-mine.test')
                ->where('can.create', false)
        );
    });
});

describe('writes are global-admin only (BR-12)', function () {
    it('lets a global admin create one', function () {
        makeDomain('one.test');

        $this->actingAs(globalAdmin())->post('/alias-domains', [
            'alias_domain' => 'alias.test', 'target_domain' => 'one.test', 'active' => true,
        ])->assertRedirect('/alias-domains');

        expect(AliasDomain::withoutDomainScope()->find('alias.test'))->not->toBeNull();
    });

    it('refuses a domain admin, even for a domain they administer', function () {
        makeDomain('mine.test');

        $this->actingAs(domainAdmin('mine.test'))->post('/alias-domains', [
            'alias_domain' => 'alias.test', 'target_domain' => 'mine.test', 'active' => true,
        ])->assertForbidden();

        expect(AliasDomain::withoutDomainScope()->find('alias.test'))->toBeNull();
    });

    it('refuses a domain admin deleting one', function () {
        makeDomain('mine.test');
        makeAliasDomain('alias.test', 'mine.test');

        $this->actingAs(domainAdmin('mine.test'))
            ->delete('/alias-domains/alias.test')->assertForbidden();

        expect(AliasDomain::withoutDomainScope()->find('alias.test'))->not->toBeNull();
    });
});

describe('validation', function () {
    it('requires the target to exist (BR-02)', function () {
        $this->actingAs(globalAdmin())->post('/alias-domains', [
            'alias_domain' => 'alias.test', 'target_domain' => 'nowhere.test', 'active' => true,
        ])->assertSessionHasErrors('target_domain');
    });

    it('rejects a duplicate alias domain (BR-04)', function () {
        makeDomain('one.test');
        makeAliasDomain('alias.test', 'one.test');

        $this->actingAs(globalAdmin())->post('/alias-domains', [
            'alias_domain' => 'alias.test', 'target_domain' => 'one.test', 'active' => true,
        ])->assertSessionHasErrors('alias_domain');
    });

    it('refuses an alias domain pointing at itself', function () {
        makeDomain('loop.test');

        $this->actingAs(globalAdmin())->post('/alias-domains', [
            'alias_domain' => 'loop.test', 'target_domain' => 'loop.test', 'active' => true,
        ])->assertSessionHasErrors('alias_domain');
    });

    it('permits a name that is also a real domain (BR-13)', function () {
        // Deliberately allowed: Q4 settled collisions as permitted, and
        // iRedMail itself does not forbid the pair.
        makeDomain('one.test');
        makeDomain('both.test');

        $this->actingAs(globalAdmin())->post('/alias-domains', [
            'alias_domain' => 'both.test', 'target_domain' => 'one.test', 'active' => true,
        ])->assertRedirect();

        expect(AliasDomain::withoutDomainScope()->find('both.test'))->not->toBeNull();
    });

    it('normalises a mixed-case name on both sides', function () {
        makeDomain('one.test');

        $this->actingAs(globalAdmin())->post('/alias-domains', [
            'alias_domain' => '  Alias.TEST ', 'target_domain' => 'ONE.test', 'active' => true,
        ])->assertRedirect();

        expect(AliasDomain::withoutDomainScope()->find('alias.test')?->target_domain)->toBe('one.test');
    });
});

describe('writes the columns the schema needs', function () {
    it('writes created and modified explicitly, and no expired column', function () {
        // alias_domain is the one table here with no `expired` column (BR-06),
        // so there is no sentinel to write.
        makeDomain('one.test');

        $this->actingAs(globalAdmin())->post('/alias-domains', [
            'alias_domain' => 'alias.test', 'target_domain' => 'one.test', 'active' => true,
        ]);

        $raw = DB::connection('vmail')->table('alias_domain')->where('alias_domain', 'alias.test')->first();

        expect($raw->created)->not->toBeNull()
            ->and($raw->modified)->not->toBeNull()
            ->and((array) $raw)->not->toHaveKey('expired')
            ->and((int) $raw->active)->toBe(1);
    });

    it('retargets without touching the name', function () {
        makeDomain('one.test');
        makeDomain('two.test');
        makeAliasDomain('alias.test', 'one.test');

        $this->actingAs(globalAdmin())->put('/alias-domains/alias.test', [
            'target_domain' => 'two.test', 'active' => true,
        ])->assertRedirect();

        expect(AliasDomain::withoutDomainScope()->find('alias.test')?->target_domain)->toBe('two.test');
    });

    it('deletes one row and no accounts', function () {
        makeDomain('one.test');
        makeAliasDomain('alias.test', 'one.test');

        $this->actingAs(globalAdmin())->delete('/alias-domains/alias.test')->assertRedirect();

        expect(AliasDomain::withoutDomainScope()->find('alias.test'))->toBeNull()
            // The target domain is untouched — this removes routing, not mail.
            ->and(DB::connection('vmail')->table('domain')->where('domain', 'one.test')->count())->toBe(1);
    });
});
