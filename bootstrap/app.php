<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Clients\Http\Middleware\EnsureClientIsActive;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Modules\Core\Http\Middleware\SetLocaleFromHeader;
use Modules\Users\Http\Middleware\EnsureUserIsActive;
use Spatie\Permission\Exceptions\UnauthorizedException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
            'active.user' => EnsureUserIsActive::class,
            'active.client' => EnsureClientIsActive::class,
        ]);

        $middleware->api(prepend: [
            SetLocaleFromHeader::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $envelope = function (int $status, ErrorCode $code, string $message, array $errors = []): Response {
            return response()->json([
                'success' => false,
                'message' => $message,
                'error_code' => $code->value,
                'errors' => $errors,
            ], $status);
        };

        $onlyApi = fn (Request $request): bool => $request->is('api/*');

        $exceptions->render(function (ValidationException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(
                422,
                ErrorCode::ValidationError,
                $e->validator->errors()->first() ?: __('core::errors.VALIDATION_ERROR'),
                $e->errors(),
            );
        });

        $exceptions->render(function (BusinessException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope($e->status, $e->errorCode, $e->getMessage(), $e->errors);
        });

        $exceptions->render(function (AuthenticationException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(401, ErrorCode::Unauthenticated, __('core::errors.UNAUTHENTICATED'));
        });

        $exceptions->render(function (UnauthorizedException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(403, ErrorCode::Forbidden, __('core::errors.FORBIDDEN'));
        });

        // AuthorizationException is converted to AccessDeniedHttpException by the
        // framework before rendering; both are registered for completeness.
        $exceptions->render(function (AuthorizationException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(403, ErrorCode::Forbidden, __('core::errors.FORBIDDEN'));
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(403, ErrorCode::Forbidden, __('core::errors.FORBIDDEN'));
        });

        // ModelNotFoundException is converted to NotFoundHttpException by the
        // framework before rendering; both are registered for completeness.
        $exceptions->render(function (ModelNotFoundException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(404, ErrorCode::NotFound, __('core::errors.NOT_FOUND'));
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(404, ErrorCode::NotFound, __('core::errors.NOT_FOUND'));
        });

        $exceptions->render(function (MethodNotAllowedHttpException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(405, ErrorCode::MethodNotAllowed, __('core::errors.METHOD_NOT_ALLOWED'));
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            return $envelope(429, ErrorCode::TooManyRequests, __('core::errors.TOO_MANY_REQUESTS'));
        });

        // Any other HTTP exception keeps its status code.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            $code = match ($e->getStatusCode()) {
                403 => ErrorCode::Forbidden,
                404 => ErrorCode::NotFound,
                405 => ErrorCode::MethodNotAllowed,
                429 => ErrorCode::TooManyRequests,
                default => ErrorCode::ServerError,
            };

            return $envelope($e->getStatusCode(), $code, __('core::errors.'.$code->value));
        });

        $exceptions->render(function (Throwable $e, Request $request) use ($envelope, $onlyApi) {
            if (! $onlyApi($request)) {
                return null;
            }

            $message = config('app.debug') && $e->getMessage() !== ''
                ? $e->getMessage()
                : __('core::errors.SERVER_ERROR');

            return $envelope(500, ErrorCode::ServerError, $message);
        });
    })->create();
