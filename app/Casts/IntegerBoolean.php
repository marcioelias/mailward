<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A flag stored as an integer, because neither iRedMail schema uses a native
 * boolean: MySQL declares TINYINT(1), PostgreSQL INT2.
 *
 * Laravel's own 'boolean' cast is not usable here. It hands a PHP bool to the
 * driver, and PDO's PostgreSQL driver binds that as 't'/'f', which a smallint
 * column rejects. MySQL accepts it silently, so the defect only appears on one
 * of the two drivers the test suite is required to cover.
 *
 * See docs/reference/schema-type-matrix.md, D4.
 *
 * @implements CastsAttributes<bool, bool|int>
 */
final class IntegerBoolean implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): bool
    {
        return (int) $value === 1;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): int
    {
        return $value ? 1 : 0;
    }
}
