<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Mail\Mailbox;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The administrator a request is acting as, and the domains they may see.
 *
 * There are two roles and no third (`docs/policies/authorization.md` §1), both
 * sourced from iRedMail rather than from a Mailward table
 * (`docs/decisions/0003-reuse-iredmail-admin-model.md`).
 */
final class Actor
{
    /** @var list<string>|null */
    private ?array $domains = null;

    public function __construct(private readonly ?Mailbox $mailbox) {}

    public static function current(): self
    {
        $user = Auth::user();

        return new self($user instanceof Mailbox ? $user : null);
    }

    public function isAuthenticated(): bool
    {
        return $this->mailbox instanceof Mailbox;
    }

    public function isGlobalAdmin(): bool
    {
        return (bool) $this->mailbox?->isglobaladmin;
    }

    public function address(): ?string
    {
        return $this->mailbox?->getAuthIdentifier();
    }

    /**
     * The domains this administrator may see. Empty for a global admin, who is
     * not filtered at all — never confuse "no restrictions" with "no domains".
     * Ask {@see isGlobalAdmin()} first.
     *
     * @return list<string>
     */
    public function administeredDomains(): array
    {
        if ($this->domains !== null) {
            return $this->domains;
        }

        $address = $this->address();

        if ($address === null) {
            return $this->domains = [];
        }

        /*
         * A global admin is represented twice: the isglobaladmin flag and a
         * domain_admins row carrying the literal sentinel 'ALL'. That sentinel
         * matches no row in `domain`, so leaving it in would make a join
         * return nothing for exactly the administrator who should see
         * everything (docs/reference/open-questions-research.md, OQ-02).
         *
         * Queried through the builder rather than the DomainAdmin model, which
         * is itself domain-scoped and would recurse.
         */
        $domains = DB::connection('vmail')
            ->table('domain_admins')
            ->where('username', $address)
            ->where('domain', '<>', 'ALL')
            ->pluck('domain')
            ->all();

        return $this->domains = array_values(array_unique(array_map(strval(...), $domains)));
    }

    public function administers(string $domain): bool
    {
        return $this->isGlobalAdmin()
            || in_array(mb_strtolower($domain), $this->administeredDomains(), true);
    }
}
