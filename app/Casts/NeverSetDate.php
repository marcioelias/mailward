<?php

declare(strict_types=1);

namespace App\Casts;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A NOT NULL date column whose "never set" state is a sentinel value rather
 * than NULL. Applies to created, modified and passwordlastchange.
 *
 * The sentinel is not symmetric across drivers. MySQL defaults these columns
 * to 1970-01-01 01:01:01; PostgreSQL defaults them to NOW(), so the sentinel
 * never appears there unless something wrote it. Nothing may therefore infer
 * "never changed" from reading these columns — the cast only translates the
 * value it finds. Mailward writes them explicitly on every insert rather than
 * letting the column default fire.
 *
 * See docs/02-domain.md §1.2 and docs/reference/schema-type-matrix.md, D1.
 *
 * @implements CastsAttributes<?CarbonImmutable, ?CarbonImmutable>
 */
final class NeverSetDate implements CastsAttributes
{
    public const SENTINEL = '1970-01-01 01:01:01';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '' || str_starts_with($value, '0000-00-00')) {
            return null;
        }

        $date = CarbonImmutable::parse($value);

        return $date->format('Y-m-d H:i:s') === self::SENTINEL ? null : $date;
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
