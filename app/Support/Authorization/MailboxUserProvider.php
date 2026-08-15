<?php

declare(strict_types=1);

namespace App\Support\Authorization;

use App\Models\Mail\Scopes\DomainScope;
use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves the authenticated administrator without applying the domain scope.
 *
 * The scope answers "which domains may this actor see", which means it has to
 * know who the actor is. Restoring the actor from the session is therefore the
 * one query that cannot be scoped: doing so asks the question of itself and
 * recurses until the process dies — which is what it did, as a 502 rather than
 * an exception, because a stack overflow does not reach the error handler.
 *
 * Tests never saw it. `actingAs`, and the request that performs the login,
 * both leave the user on the guard in memory, so `Auth::user()` returns
 * without ever reaching this provider. Only a fresh request rebuilding the
 * session from a cookie takes this path, which is every request a browser
 * makes after signing in.
 */
final class MailboxUserProvider extends EloquentUserProvider
{
    /**
     * @param  Model|null  $model
     * @return Builder<Model>
     */
    protected function newModelQuery($model = null)
    {
        return parent::newModelQuery($model)
            ->withoutGlobalScope(DomainScope::class);
    }
}
