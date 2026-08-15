<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

/**
 * Lower cases every address a request carries, before validation runs.
 *
 * `docs/decisions/0005-lowercase-canonical-addresses.md` puts this at the
 * request boundary deliberately: trim, then lower case, then validate. It
 * applies to lookups as well as writes, so a request that merely filters by
 * address normalises too.
 *
 * The stakes are not cosmetic. Dovecot always looks a lower case address up,
 * so a mixed-case row is unreachable on PostgreSQL — the account has no home
 * and receives no mail (`docs/02-domain.md` §1.1).
 *
 * A request using this trait must not define `prepareForValidation()` itself.
 * If it needs more preparation, override `normalisedInput()` instead.
 */
trait NormalisesAddresses
{
    /**
     * Input keys holding an address or a domain name. A key may hold a single
     * string or a list of strings; both are normalised.
     *
     * @return list<string>
     */
    abstract protected function addressFields(): array;

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalisedInput());
    }

    /**
     * @return array<string, string|array<array-key, mixed>>
     */
    protected function normalisedInput(): array
    {
        $normalised = [];

        foreach ($this->addressFields() as $field) {
            $value = $this->input($field);

            if (is_string($value)) {
                $normalised[$field] = self::canonicalise($value);

                continue;
            }

            if (is_array($value)) {
                $normalised[$field] = array_map(
                    fn (mixed $item): mixed => is_string($item) ? self::canonicalise($item) : $item,
                    $value,
                );
            }
        }

        return $normalised;
    }

    private static function canonicalise(string $value): string
    {
        return mb_strtolower(trim($value));
    }
}
