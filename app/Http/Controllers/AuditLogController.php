<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\AuditEntry;
use App\Policies\AuditEntryPolicy;
use App\Support\Audit\Audit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Reading the audit log (docs/features/audit-log.md).
 *
 * **Global admins only** (BR-16, Q3=A). A domain admin gets 403 and no entry
 * reaches any prop, whatever the interface offered them; the decision is
 * {@see AuditEntryPolicy} and it is taken on the server on every
 * request (BR-14).
 *
 * The whole controller is one read method, and that is the contract: there is
 * no store, update or destroy here and no route resolves for one. Entries are
 * never modified and never deleted individually — the only DELETE that exists
 * anywhere against this table is the age-based retention prune, which is
 * scheduled and never requested (BR-05, BR-21).
 *
 * Nothing here joins `audit_log` to a `vmail` table. The log lives in
 * Mailward's own database and its targets are plain strings with no foreign
 * key, so an entry naming an account that no longer exists still lists
 * (BR-07, BR-08, BR-13).
 */
final class AuditLogController extends Controller
{
    private const PER_PAGE = 25;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', AuditEntry::class);

        /*
         * Two filters, both on a fixed column. No client value ever names a
         * column or a sort direction, so there is no ordering input to
         * validate: newest first is the only order this screen has. A value
         * outside the allowlist is dropped and never reaches a query.
         */
        $actors = $this->distinctValues('actor');
        $events = $this->distinctValues('event');

        $actor = $this->allowed($request->query('actor'), $actors);
        $event = $this->allowed($request->query('event'), $events);

        $entries = AuditEntry::query()
            ->when($actor !== '', fn ($query) => $query->where('actor', $actor))
            ->when($event !== '', fn ($query) => $query->where('event', $event))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return Inertia::render('AuditLog/Index', [
            'entries' => $entries->through($this->present(...)),
            'filters' => ['actor' => $actor, 'event' => $event],
            'actors' => $this->options($actors),
            'events' => $this->options($events),
        ]);
    }

    /**
     * One entry, shaped for reading rather than for storage.
     *
     * The payload's `old` and `attributes` are flattened into field-level
     * before/after pairs so the reader sees what changed instead of a blob of
     * JSON. Nothing is redacted here: secrets are stripped at write time by
     * {@see Audit}, at the single point every write funnels
     * through (BR-04). A second pass at read time would imply the first one is
     * unreliable, and would hide a leak rather than prevent it.
     *
     * @return array{
     *     id: int,
     *     occurredAt: ?string,
     *     actor: ?string,
     *     event: ?string,
     *     description: string,
     *     target: array{type: ?string, id: ?string},
     *     ip: ?string,
     *     changes: list<array{field: string, from: ?string, to: ?string}>,
     *     removed: array<string, int>
     * }
     */
    private function present(AuditEntry $entry): array
    {
        $properties = $entry->properties?->toArray() ?? [];

        $before = $this->flatten(Arr::get($properties, 'old'));
        $after = $this->flatten(Arr::get($properties, 'attributes'));

        /*
         * A cascade is one entry for the whole operation, and its payload
         * carries the counts it removed per table so the entry names what it
         * destroyed rather than merely that it destroyed something (BR-19).
         * Those belong beside the entry, not among the field changes.
         */
        $removed = [];

        foreach (Arr::get($properties, 'old.removed', []) as $table => $count) {
            if (is_scalar($count)) {
                $removed[(string) $table] = (int) $count;
                unset($before['removed.'.$table]);
            }
        }

        $fields = array_keys($before + $after);
        sort($fields);

        return [
            'id' => (int) $entry->getKey(),
            // The migration keeps Laravel's `created_at`; the specification
            // calls the field `occurred_at`. Same instant, named as specified.
            'occurredAt' => $entry->created_at?->toIso8601String(),
            'actor' => $entry->actor,
            'event' => $entry->event,
            'description' => (string) $entry->description,
            'target' => [
                // A deletion has no surviving row for the morph to point at,
                // so the type and identifier are carried in the payload.
                'type' => $this->targetType($entry, $properties),
                'id' => $entry->subject_id ?? $this->stringOrNull(Arr::get($properties, 'identifier')),
            ],
            'ip' => $entry->ip_address,
            'changes' => array_map(fn (string $field): array => [
                'field' => $field,
                'from' => $before[$field] ?? null,
                'to' => $after[$field] ?? null,
            ], $fields),
            'removed' => $removed,
        ];
    }

    /**
     * @param  array<string, mixed>  $properties
     */
    private function targetType(AuditEntry $entry, array $properties): ?string
    {
        if (is_string($entry->subject_type) && $entry->subject_type !== '') {
            return class_basename($entry->subject_type);
        }

        return $this->stringOrNull(Arr::get($properties, 'type'));
    }

    /**
     * Nested payloads become dotted field names — `removed.mailboxes` rather
     * than a nested object the template would have to walk.
     *
     * @return array<string, string>
     */
    private function flatten(mixed $values, string $prefix = ''): array
    {
        if (! is_array($values)) {
            return [];
        }

        $flat = [];

        foreach ($values as $key => $value) {
            $field = $prefix === '' ? (string) $key : $prefix.'.'.$key;

            if (is_array($value)) {
                $flat += $this->flatten($value, $field);

                continue;
            }

            $flat[$field] = $this->readable($value);
        }

        return $flat;
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private function readable(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? __('Yes') : __('No'),
            is_scalar($value) => (string) $value,
            default => '—',
        };
    }

    /**
     * The values actually present in the log, which is what the filter offers
     * and therefore what it accepts.
     *
     * @return list<string>
     */
    private function distinctValues(string $column): array
    {
        return AuditEntry::query()
            ->whereNotNull($column)
            ->distinct()
            ->orderBy($column)
            ->pluck($column)
            ->map(strval(...))
            ->reject(fn (string $value): bool => $value === '')
            ->values()
            ->all();
    }

    /**
     * @param  list<string>  $allowed
     */
    private function allowed(mixed $value, array $allowed): string
    {
        $value = is_string($value) ? trim($value) : '';

        return in_array($value, $allowed, true) ? $value : '';
    }

    /**
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    private function options(array $values): array
    {
        return array_map(fn (string $value): array => ['value' => $value, 'label' => $value], $values);
    }
}
