<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /*
     * Authorization happens at the HTTP boundary, before an Action runs. An
     * Action must stay callable from the console and from tests, where there
     * is no authenticated actor at all (standards/laravel/authorization.md).
     */
    use AuthorizesRequests;
}
