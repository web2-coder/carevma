<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['web', 'auth']],
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => \App\Http\Middleware\AdminMiddleware::class,
            'employee' => \App\Http\Middleware\EmployeeMiddleware::class,
            'role.redirect' => \App\Http\Middleware\RedirectIfRole::class,
            'profile.complete' => \App\Http\Middleware\EnsureProfileIsComplete::class,
            'role' => \App\Http\Middleware\RoleMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request, Throwable $e) => $request->is('api/*') || $request->expectsJson()
        );

        $exceptions->render(function (ValidationException $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) return null;

            $errors = $e->errors();
            $first = collect($errors)->flatten()->first();
            return response()->json([
                'message' => $first ?: 'Please review the highlighted fields and try again.',
                'errors' => $errors,
                'code' => 'VALIDATION_FAILED',
            ], 422);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) return null;
            return response()->json([
                'message' => 'Your session has expired. Please sign in again.',
                'code' => 'AUTHENTICATION_REQUIRED',
            ], 401);
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) return null;
            return response()->json([
                'message' => 'You do not have permission to perform this action.',
                'code' => 'PERMISSION_DENIED',
            ], 403);
        });

        $exceptions->render(function (ModelNotFoundException $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) return null;
            return response()->json([
                'message' => 'The requested record could not be found. It may have been removed or changed.',
                'code' => 'RECORD_NOT_FOUND',
            ], 404);
        });

        $exceptions->render(function (QueryException $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) return null;

            Log::error('CareVMA database operation failed.', [
                'request_id' => $request->headers->get('X-Request-ID'),
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->id,
                'sql_state' => $e->errorInfo[0] ?? null,
                'driver_code' => $e->errorInfo[1] ?? null,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'We could not save your changes because the database rejected the request. Please refresh and try again. If the problem continues, contact an administrator.',
                'code' => 'DATABASE_OPERATION_FAILED',
            ], 500);
        });

        $exceptions->render(function (\Throwable $e, Request $request) {
            if (!$request->is('api/*') && !$request->expectsJson()) return null;

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            if ($status < 500) {
                $message = match ($status) {
                    403 => 'You do not have permission to perform this action.',
                    404 => 'The requested page or record could not be found.',
                    405 => 'This action is not available from the current page.',
                    409 => 'This record changed while you were working. Refresh the page and try again.',
                    419 => 'Your session security token expired. Refresh the page and try again.',
                    429 => 'Too many requests were sent. Please wait a moment and try again.',
                    default => 'The request could not be completed. Please review your input and try again.',
                };
                return response()->json(['message' => $message, 'code' => 'REQUEST_FAILED'], $status);
            }

            Log::error('Unexpected CareVMA API failure.', [
                'request_id' => $request->headers->get('X-Request-ID'),
                'method' => $request->method(),
                'path' => $request->path(),
                'user_id' => $request->user()?->id,
                'exception' => $e,
            ]);

            return response()->json([
                'message' => 'CareVMA could not complete this request because of an unexpected server problem. Your data was not intentionally changed. Please try again, and contact an administrator if it continues.',
                'code' => 'UNEXPECTED_SERVER_ERROR',
            ], 500);
        });
    })->create();
