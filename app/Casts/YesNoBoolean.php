<?php

declare(strict_types=1);

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * A flag stored as the literal character 'y' or 'n'.
 *
 * Only the three SOGo columns use this form — enablesogowebmail,
 * enablesogocalendar and enablesogoactivesync. Both iRedMail schema files
 * carry a comment stating the character type is required rather than an int,
 * and writing 1 instead of 'y' breaks SOGo without any error.
 *
 * See docs/02-domain.md §1.3 and docs/reference/schema-type-matrix.md, D5.
 *
 * @implements CastsAttributes<bool, bool|string>
 */
final class YesNoBoolean implements CastsAttributes
{
    private const YES = 'y';

    private const NO = 'n';

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): bool
    {
        return is_string($value) && strtolower($value) === self::YES;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): string
    {
        return $value ? self::YES : self::NO;
    }
}
