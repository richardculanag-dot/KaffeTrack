<?php

use App\Http\Middleware\EnsureUserHasRole;
use App\Http\Middleware\EnsureUserIsActive;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => EnsureUserHasRole::class,
            'active' => EnsureUserIsActive::class,
        ]);

        // Guests are sent to the login page; signed-in users visiting /login go to their home screen.
        $middleware->redirectGuestsTo(fn () => route('login'));
        $middleware->redirectUsersTo(fn () => route('home'));

        // Trust the hosting provider's proxy so HTTPS and client IPs (used in audit logs) are correct.
        $middleware->trustProxies(at: '*');
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // A browser trying to change a server-only (#[Locked]) value is tampering, not a bug:
        // answer 403 Forbidden instead of a 500 error, and keep it out of the error log.
        $exceptions->render(fn (CannotUpdateLockedPropertyException $e) => abort(403, 'This action is not allowed.'));
        $exceptions->dontReport(CannotUpdateLockedPropertyException::class);
    })->create();
