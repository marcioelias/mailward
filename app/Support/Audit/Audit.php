<?php

declare(strict_types=1);

namespace App\Support\Audit;

use Illuminate\Database\Eloquent\Model;

/**
 * Records a write that Mailward performed.
 *
 * **Called after the mail write has committed**, never before. No transaction
 * can span both databases (`docs/01-architecture.md` §3), so the choice is
 * between recording something that might not have happened and losing the
 * record of something that did. Recording only what committed was the decision
 * (`docs/features/audit-log.md`, OQ-AUD-03): a log that lies about an
 * operation is worse than a log with a rare gap, and the gap is detectable
 * where the false entry is not.
 *
 * Passwords, hashes and secrets never reach this. They are stripped here, at
 * the one place every write funnels through, rather than trusted to be omitted
 * by each caller.
 */
final class Audit
{
    /** @var list<string> */
    private const NEVER_RECORDED = [
        'password',
        'password_confirmation',
        'current_password',
        'two_factor_secret',
        'remember_token',
    ];

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     */
    public static function record(
        string $event,
        Model $subject,
        array $before = [],
        array $after = [],
        string $description = '',
    ): void {
        activity()
            ->performedOn($subject)
            ->event($event)
            ->withProperties([
                'old' => self::redact($before),
                'attributes' => self::redact($after),
            ])
            ->log($description !== '' ? $description : $event);
    }

    /**
     * Recorded without a surviving subject — a deletion, where the row the
     * morph would point at no longer exists by the time anyone reads this.
     *
     * @param  array<string, mixed>  $before
     */
    public static function recordDeletion(string $type, string $identifier, array $before = []): void
    {
        activity()
            ->event('deleted')
            ->withProperties([
                'type' => $type,
                'identifier' => $identifier,
                'old' => self::redact($before),
            ])
            ->log('deleted');
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private static function redact(array $attributes): array
    {
        foreach (self::NEVER_RECORDED as $secret) {
            if (array_key_exists($secret, $attributes)) {
                $attributes[$secret] = '[redacted]';
            }
        }

        return $attributes;
    }
}
