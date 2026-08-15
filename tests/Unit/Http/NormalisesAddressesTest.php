<?php

declare(strict_types=1);

use App\Http\Requests\Concerns\NormalisesAddresses;
use Illuminate\Foundation\Http\FormRequest;

/**
 * ADR-0005 puts address canonicalisation at the request boundary. A mixed-case
 * row is unreachable on PostgreSQL, so this is the single point that keeps one
 * from ever being written.
 */
function requestWith(array $input): FormRequest
{
    $request = new class extends FormRequest
    {
        use NormalisesAddresses;

        protected function addressFields(): array
        {
            return ['username', 'domain', 'members'];
        }

        public function normalise(): array
        {
            return $this->normalisedInput();
        }
    };

    return $request::create('/', 'POST', $input);
}

it('lower cases and trims a single address', function () {
    $normalised = requestWith(['username' => '  Joao.Silva@Empresa.COM '])->normalise();

    expect($normalised['username'])->toBe('joao.silva@empresa.com');
});

it('lower cases a domain name', function () {
    expect(requestWith(['domain' => 'Empresa.COM.BR'])->normalise()['domain'])
        ->toBe('empresa.com.br');
});

it('normalises every address in a list', function () {
    $normalised = requestWith(['members' => ['A@X.com', ' b@Y.COM ']])->normalise();

    expect($normalised['members'])->toBe(['a@x.com', 'b@y.com']);
});

it('leaves fields it was not given alone', function () {
    expect(requestWith(['description' => 'Leave This Alone'])->normalise())
        ->not->toHaveKey('description');
});

it('lower cases the non-ascii parts too', function () {
    expect(requestWith(['username' => 'JOÃO@EMPRESA.COM'])->normalise()['username'])
        ->toBe('joão@empresa.com');
});
