<?php

declare(strict_types=1);

namespace App\Actions\Domains;

use App\Casts\NeverExpiresDate;
use App\Casts\NeverSetDate;
use App\Models\Mail\Domain;
use App\Support\Audit\Audit;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a domain record.
 *
 * The date columns are written explicitly rather than left to the column
 * default (`docs/features/domains.md` BR-08, BR-09): the "never set" sentinel
 * exists on MySQL and not on PostgreSQL, where the same column defaults to
 * `NOW()`, so relying on the default produces a different row per driver.
 */
final class SaveDomainAction
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): Domain
    {
        $domain = DB::connection('vmail')->transaction(function () use ($attributes): Domain {
            $domain = new Domain($this->writable($attributes));

            $domain->setAttribute('created', now());
            $domain->setAttribute('modified', now());

            // Written as the sentinel, meaning "never expires", not as null.
            $domain->setAttribute('expired', NeverExpiresDate::SENTINEL);

            $domain->save();

            return $domain;
        });

        // After the commit, never before: a log that claims an operation that
        // did not happen is worse than one with a rare, detectable gap.
        Audit::record('created', $domain, after: $domain->getAttributes());

        return $domain;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function update(Domain $domain, array $attributes): Domain
    {
        $before = $domain->getOriginal();

        DB::connection('vmail')->transaction(function () use ($domain, $attributes): void {
            $domain->fill($this->writable($attributes));
            $domain->setAttribute('modified', now());
            $domain->save();
        });

        Audit::record(
            'updated',
            $domain,
            before: array_intersect_key($before, $domain->getChanges()),
            after: $domain->getChanges(),
        );

        return $domain;
    }

    /**
     * `description` and `disclaimer` are nullable TEXT on MySQL and NOT NULL
     * DEFAULT '' on PostgreSQL, so a PHP null succeeds on one driver and raises
     * a not-null violation on the other. Normalised to '' on write
     * (BR-11, schema-type-matrix D6).
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function writable(array $attributes): array
    {
        foreach (['description', 'disclaimer'] as $optional) {
            if (array_key_exists($optional, $attributes)) {
                $attributes[$optional] = (string) ($attributes[$optional] ?? '');
            }
        }

        return $attributes;
    }

    /**
     * The sentinel a fresh row carries for "never set", kept here so the
     * meaning travels with the writer rather than being repeated as a literal.
     */
    public function neverSet(): string
    {
        return NeverSetDate::SENTINEL;
    }
}
