<?php

declare(strict_types=1);

use App\Casts\IntegerBoolean;
use App\Casts\NeverExpiresDate;
use App\Casts\NeverSetDate;
use App\Casts\YesNoBoolean;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * These casts exist because the MySQL and PostgreSQL schema files iRedMail
 * ships diverge, and every divergence below produces a defect on exactly one
 * driver. See docs/reference/schema-type-matrix.md.
 */
function model(): Model
{
    return new class extends Model {};
}

describe('IntegerBoolean (D4 — no native boolean on either driver)', function () {
    $cast = new IntegerBoolean;

    it('reads the integer the driver returns', function () use ($cast) {
        expect($cast->get(model(), 'active', 1, []))->toBeTrue()
            ->and($cast->get(model(), 'active', '1', []))->toBeTrue()
            ->and($cast->get(model(), 'active', 0, []))->toBeFalse()
            ->and($cast->get(model(), 'active', '0', []))->toBeFalse();
    });

    it('writes an integer, never a PHP boolean', function () use ($cast) {
        // PDO's PostgreSQL driver binds a PHP bool as 't'/'f', which INT2
        // rejects. MySQL would have accepted it, hiding the bug on one driver.
        expect($cast->set(model(), 'active', true, []))->toBe(1)
            ->and($cast->set(model(), 'active', false, []))->toBe(0);
    });
});

describe('YesNoBoolean (D5 — the SOGo character columns)', function () {
    $cast = new YesNoBoolean;

    it('reads the character flag', function () use ($cast) {
        expect($cast->get(model(), 'enablesogowebmail', 'y', []))->toBeTrue()
            ->and($cast->get(model(), 'enablesogowebmail', 'Y', []))->toBeTrue()
            ->and($cast->get(model(), 'enablesogowebmail', 'n', []))->toBeFalse();
    });

    it('writes y or n, never 1 or 0', function () use ($cast) {
        // Writing 1 here breaks SOGo, and nothing reports an error.
        expect($cast->set(model(), 'enablesogowebmail', true, []))->toBe('y')
            ->and($cast->set(model(), 'enablesogowebmail', false, []))->toBe('n');
    });
});

describe('NeverSetDate (D1 — the sentinel MySQL has and PostgreSQL does not)', function () {
    $cast = new NeverSetDate;

    it('reads the never-set sentinel as null', function () use ($cast) {
        expect($cast->get(model(), 'created', '1970-01-01 01:01:01', []))->toBeNull();
    });

    it('reads a real date as a date', function () use ($cast) {
        $date = $cast->get(model(), 'created', '2026-03-14 09:30:00', []);

        expect($date)->toBeInstanceOf(CarbonImmutable::class)
            ->and($date->format('Y-m-d H:i:s'))->toBe('2026-03-14 09:30:00');
    });

    it('tolerates the zero date some MySQL servers return', function () use ($cast) {
        expect($cast->get(model(), 'created', '0000-00-00 00:00:00', []))->toBeNull()
            ->and($cast->get(model(), 'created', '', []))->toBeNull();
    });

    it('writes the sentinel rather than leaving the column default to fire', function () use ($cast) {
        expect($cast->set(model(), 'created', null, []))->toBe('1970-01-01 01:01:01');
    });
});

describe('NeverExpiresDate (D2 — the two drivers disagree on the sentinel)', function () {
    $cast = new NeverExpiresDate;

    it('reads the MySQL never-expires sentinel as null', function () use ($cast) {
        expect($cast->get(model(), 'expired', '9999-12-31 00:00:00', []))->toBeNull();
    });

    it('reads the PostgreSQL never-expires sentinel as null too', function () use ($cast) {
        // This is the whole point of the cast. The two schema files ship
        // different values, so comparing against one constant is wrong on the
        // other driver.
        expect($cast->get(model(), 'expired', '9999-12-31 01:01:01', []))->toBeNull();
    });

    it('reads a real expiry date as a date', function () use ($cast) {
        $date = $cast->get(model(), 'expired', '2027-01-31 23:59:59', []);

        expect($date)->toBeInstanceOf(CarbonImmutable::class)
            ->and($date->format('Y-m-d H:i:s'))->toBe('2027-01-31 23:59:59');
    });

    it('writes one canonical sentinel', function () use ($cast) {
        expect($cast->set(model(), 'expired', null, []))->toBe('9999-12-31 00:00:00');
    });
});
