<?php

declare(strict_types=1);

namespace App\Providers;

use App\Actions\AuthenticateAdministrator;
use App\Support\PasswordScheme\SchemeRegistry;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Laravel\Fortify\Fortify;

final class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SchemeRegistry::class, fn (): SchemeRegistry => SchemeRegistry::fromConfig());
    }

    public function boot(): void
    {
        Fortify::loginView(fn () => Inertia::render('Auth/Login'));

        Fortify::authenticateUsing(function (Request $request) {
            return app(AuthenticateAdministrator::class)->handle(
                (string) $request->input('email', ''),
                (string) $request->input('password', ''),
            );
        });

        $this->configureRateLimiting();
    }

    /**
     * The login form is a password oracle against every real mail account on
     * the server, so it is limited harder than an ordinary application login
     * (docs/decisions/0003, docs/features/authentication.md BR-08).
     *
     * Five attempts per minute, keyed by IP **and** submitted address
     * together. Keying by address alone would let one attacker lock a known
     * administrator out of their own panel; keying by IP alone would let one
     * host walk the whole account list from behind a single address.
     *
     * The number itself is a chosen default, not a sourced one — the
     * specification requires "aggressive" without naming a figure. Recorded as
     * OQ-AUTH-02 in docs/features/authentication.md.
     */
    private function configureRateLimiting(): void
    {
        RateLimiter::for('login', function (Request $request): Limit {
            $address = Str::lower((string) $request->input('email', ''));

            return Limit::perMinute(5)->by($address.'|'.$request->ip());
        });

        RateLimiter::for('two-factor', function (Request $request): Limit {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });
    }
}
