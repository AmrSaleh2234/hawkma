<?php

namespace Modules\Clients\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Symfony\Component\HttpFoundation\Response;

class EnsureClientIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user('client');

        if ($client && ! $client->is_active) {
            throw new BusinessException(ErrorCode::AccountDisabled, status: 403);
        }

        return $next($request);
    }
}
