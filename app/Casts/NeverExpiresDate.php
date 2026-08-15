<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The `expired` column, whose "never expires" state is a far-future sentinel
 * rather than NULL.
 *
 * The two schema files disagree on the exact value: 9999-12-31 00:00:00 on
 * MySQL, 9999-12-31 01:01:01 on PostgreSQL. Comparing for equality against
 * either constant is therefore wrong on one driver, so this cast treats any
 * date on the sentinel day as "never expires" and writes one canonical value.
 *
 * Expiry itself is never decided by this cast. It is tested in the query as
 * `expired > now()`, which is correct regardless of which sentinel a row
 * happens to carry.
 *
 * See docs/02-domain.md §1.2 and docs/reference/schema-type-matrix.md, D2.
 *
 * @implements CastsAttributes<?CarbonImmutable, ?CarbonImmutable>
 */
final class NeverExpiresDate implements CastsAttributes
{
    public const SENTINEL = '9999-12-31 00:00:00';

    private const SENTINEL_DAY = '9999-12-31';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $date = CarbonImmutable::parse($value);

        return $date->format('Y-m-d') === self::SENTINEL_DAY ? null : $date;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        if ($value === null) {
            return self::SENTINEL;
        }

        return CarbonImmutable::parse($value)->format('Y-m-d H:i:s');
    }
}
