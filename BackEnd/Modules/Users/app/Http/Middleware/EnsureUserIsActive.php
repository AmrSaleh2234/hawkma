<?php

namespace Modules\Users\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Core\Enums\ErrorCode;
use Modules\Core\Exceptions\BusinessException;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('admin');

        if ($user && ! $user->is_active) {
            throw new BusinessException(ErrorCode::AccountDisabled, status: 403);
        }

        return $next($request);
    }
}
